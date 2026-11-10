<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use App\Services\SystemReferencesExportService;
use App\Traits\DescribesSystemReferencesFileRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportSystemReferencesJsonBucketCommand extends Command
{
    use DescribesSystemReferencesFileRun;

    protected $signature = 'exportSystemReferencesJson:bucket';

    protected $description = 'Put the system references whose values were not sent yet (system_references.is_exported = 0) in the day\'s file in the bucket (export-json/system-references/SrvOnClick_r2b_system_references_YYYY-MM-DD.json) and mark them sent (is_exported = 1). The way out while the digital hatch is off; with HATCH_ENABLED=true digital-hatch:send-system-references does this and also puts the file on the FTP';

    public function handle(SystemReferencesExportService $exportService): int
    {
        try {
            $result = $exportService->exportToBucket();

            if (isset($result['skipped'])) {
                $this->info(match ($result['skipped']) {
                    'digital_hatch' => 'Nothing written: the digital hatch is on (HATCH_ENABLED=true), so digital-hatch:send-system-references sends the file and writes it to the bucket.',
                    'export_json_disabled' => 'Nothing written: the export is disabled (EXPORT_JSON_ENABLED=false); the records keep waiting.',
                    default => 'Nothing written: another run is writing the system references file.',
                });

                return self::SUCCESS;
            }

            $this->info($this->describeSystemReferencesFile($result['built']));
            $this->info($this->describeSystemReferencesMarked($result['marked']));

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            Log::info('ExportSystemReferencesJsonBucketCommand: ' . ($result['built']['written'] ? 'file written' : 'nothing written') . ", {$result['marked']} marked, " . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('ExportSystemReferencesJsonBucketCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

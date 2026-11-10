<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use App\Services\SystemReferencesExportService;
use App\Traits\DescribesSystemReferencesFileRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportSysRefJsonBucketCommand extends Command
{
    use DescribesSystemReferencesFileRun;

    protected $signature = 'exportSysRefJson:bucket';

    protected $description = 'Put the system references whose values were not sent yet (system_references.is_exported = 0) in the day\'s file in the bucket (export-json/system-references/SrvOnClick_r2b_system_references_YYYY-MM-DD.json) and mark them sent (is_exported = 1). exportSysRefJson:ftp also puts the file on the FTP';

    public function handle(SystemReferencesExportService $exportService): int
    {
        try {
            $result = $exportService->exportToBucket();

            if (isset($result['skipped'])) {
                $this->info(match ($result['skipped']) {
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

            Log::info('ExportSysRefJsonBucketCommand: ' . ($result['built']['written'] ? 'file written' : 'nothing written') . ", {$result['marked']} marked, " . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('ExportSysRefJsonBucketCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

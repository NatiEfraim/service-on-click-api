<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use App\Services\UsersExportService;
use App\Traits\DescribesUsersFileRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportUsersJsonBucketCommand extends Command
{
    use DescribesUsersFileRun;

    protected $signature = 'exportUsersJson:bucket';

    protected $description = 'Put the people whose roles were not sent yet (users.is_exported_role = 0) with their booking types in the day\'s users file in the bucket (export-json/users/SrvOnClick_r2b_users_YYYY-MM-DD.json) and mark them sent (is_exported_role = 1). The way out while the digital hatch is off; with HATCH_ENABLED=true digital-hatch:send-users does this and also puts the file on the FTP';

    public function handle(UsersExportService $exportService): int
    {
        try {
            $result = $exportService->exportToBucket();

            if (isset($result['skipped'])) {
                $this->info(match ($result['skipped']) {
                    'digital_hatch' => 'Nothing written: the digital hatch is on (HATCH_ENABLED=true), so digital-hatch:send-users sends the users and writes this same file to the bucket.',
                    'export_json_disabled' => 'Nothing written: the export is disabled (EXPORT_JSON_ENABLED=false); the people keep waiting.',
                    default => 'Nothing written: another run is writing the users file.',
                });

                return self::SUCCESS;
            }

            $this->info($this->describeUsersFile($result['built']));
            $this->info($this->describeUsersMarked($result['marked']));

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }

            Log::info('ExportUsersJsonBucketCommand: ' . ($result['built']['written'] ? 'file written' : 'nothing written') . ", {$result['marked']} marked, " . count($result['errors']) . ' failed.');

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('ExportUsersJsonBucketCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

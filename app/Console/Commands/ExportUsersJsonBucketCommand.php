<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use App\Services\UsersExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportUsersJsonBucketCommand extends Command
{
    protected $signature = 'exportUsersJson:bucket';

    protected $description = 'Write the r2b users file to the bucket (hatch-off path).';

    public function handle(UsersExportService $exportService): int
    {
        try {
            $result = $exportService->exportToBucket();

            if (isset($result['skipped'])) {
                return self::SUCCESS;
            }

            foreach ($result['errors'] as $error) {
                Log::error('ExportUsersJsonBucketCommand: ' . $error);
                $this->error($error);
            }

            return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Exception $e) {
            Log::error('ExportUsersJsonBucketCommand: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ExportSysRefJsonBucketJob;
use App\Services\StorageService;
use App\Services\SystemReferencesExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportSysRefJsonBucketCommand extends Command
{
    protected $signature = 'exportSysRefJson:bucket {--sync}';

    protected $description = 'Write the r2b system references file to the bucket (hatch-off path).';

    public function handle(): int
    {
        try {
            if (!$this->option('sync')) {
                ExportSysRefJsonBucketJob::dispatch();

                return self::SUCCESS;
            }

            app(SystemReferencesExportService::class)->exportToBucket();

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error('ExportSysRefJsonBucketCommand: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

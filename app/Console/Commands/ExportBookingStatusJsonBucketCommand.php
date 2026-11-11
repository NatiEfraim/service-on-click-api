<?php

namespace App\Console\Commands;

use App\Jobs\ExportBookingStatusJsonBucketJob;
use App\Services\BookingStatusExportService;
use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExportBookingStatusJsonBucketCommand extends Command
{
    protected $signature = 'exportBookingStatusJson:bucket {--sync : Write the files here, instead of queueing a job}';

    protected $description = 'Write the r2b booking status file to the bucket.';

    public function handle(): int
    {
        try {
            if (!$this->option('sync')) {
                ExportBookingStatusJsonBucketJob::dispatch();

                return self::SUCCESS;
            }

            app(BookingStatusExportService::class)->run();

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error('ExportBookingStatusJsonBucketCommand: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

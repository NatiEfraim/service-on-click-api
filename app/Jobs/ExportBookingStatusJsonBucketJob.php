<?php

namespace App\Jobs;

use App\Services\BookingStatusExportService;
use App\Services\StorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Writes the r2b booking status, values and config files to the bucket: BookingStatusExportService::run. */
class ExportBookingStatusJsonBucketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct()
    {
        $this->tries = (int) config('app.jobs.digital_hatch_send_booking_status.tries');
        $this->backoff = (int) config('app.jobs.digital_hatch_send_booking_status.backoff');
        $this->timeout = (int) config('app.jobs.digital_hatch_send_booking_status.timeout');
    }

    public function handle(): void
    {
        try {
            app(BookingStatusExportService::class)->run();
        } catch (\Exception $e) {
            Log::error('ExportBookingStatusJsonBucketJob: failed, will retry: ' . $e->getMessage());
            throw $e;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

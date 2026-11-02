<?php

namespace App\Traits;

use App\Jobs\ImportBookingJsonBucketJob;
use App\Jobs\ImportBookingJsonFtpJob;
use App\Services\DigitalHatchService;
use App\Services\ImportBookingService;
use Illuminate\Support\Facades\Log;

trait ImportsBookingJson
{
    /** Imports waiting booking files when enabled; $sync runs the chunk jobs inline. (What GET /api/test-import runs.) */
    protected function importBookingJson(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Booking import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['disabled' => true, 'busy' => false, 'files' => [], 'errors' => []];
        }

        return app(ImportBookingService::class)->importFromBucket($sync);
    }

    /**
     * importBookingJson:bucket - queues ImportBookingJsonBucketJob (a queue worker runs it), or with $sync imports the files waiting in the bucket
     * here, rows included, and returns what it did.
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importBookingJsonFromBucket(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Booking import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'IMPORT_JSON_ENABLED=false'];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => ['imported' => app(ImportBookingService::class)->importFromBucket(true)]];
        }

        dispatch(new ImportBookingJsonBucketJob());

        return ['enabled' => true, 'queued' => true];
    }

    /**
     * importBookingJson:ftp - queues ImportBookingJsonFtpJob, or with $sync takes the new booking files from the digital hatch into the bucket and
     * imports them here. Off while HATCH_ENABLED=false.
     * @param bool $force take the files again even when they were taken before
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importBookingJsonFromFtp(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Booking import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'HATCH_ENABLED=false'];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => app(DigitalHatchService::class)->importBookings($force, true)];
        }

        dispatch(new ImportBookingJsonFtpJob($force));

        return ['enabled' => true, 'queued' => true];
    }
}

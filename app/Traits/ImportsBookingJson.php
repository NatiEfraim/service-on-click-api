<?php

namespace App\Traits;

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
     * importBookingJson:bucket - the command reads the booking files waiting in the bucket (ImportBookingService), queues their rows as chunk jobs
     * (ImportBookingJsonChunkJob: a queue worker saves them), moves each file to loaded-json and returns what it did. With $sync the rows are saved
     * here instead, so no worker is needed.
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importBookingJsonFromBucket(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Booking import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'IMPORT_JSON_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => ['imported' => app(ImportBookingService::class)->importFromBucket($sync)]];
    }

    /**
     * importBookingJson:ftp - the command takes the new booking files from the digital hatch (the FTP, or the local test folder) into the bucket and
     * imports them like importBookingJson:bucket: rows queued as chunk jobs (or saved here with $sync), files moved to loaded-json, names noted in
     * digital_hatch_fetched_files. Off while HATCH_ENABLED=false.
     * @param bool $force take the files again even when they were taken before
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importBookingJsonFromFtp(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Booking import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'HATCH_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => app(DigitalHatchService::class)->importBookings($force, $sync)];
    }
}

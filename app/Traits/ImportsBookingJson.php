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

    /** Reads booking files from the bucket and queues chunk jobs ($sync saves inline). @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>} */
    protected function importBookingJsonFromBucket(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Booking import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'IMPORT_JSON_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => ['imported' => app(ImportBookingService::class)->importFromBucket($sync)]];
    }

    /** Fetches new booking files from the digital hatch FTP into the bucket, then imports them. Off while HATCH_ENABLED=false. @param bool $force re-fetch already-taken files @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>} */
    protected function importBookingJsonFromFtp(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Booking import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'HATCH_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => app(DigitalHatchService::class)->importBookings($force, $sync)];
    }
}

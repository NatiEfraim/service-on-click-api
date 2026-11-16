<?php

namespace App\Traits;

use App\Services\DigitalHatchService;
use App\Services\ImportBookingService;
use Illuminate\Support\Facades\Log;

trait ImportsBookingJson
{
    /** Imports booking files waiting in the bucket ($sync saves inline, else chunk jobs are queued). @return bool the import ran (false = disabled); throws when anything failed */
    protected function importBookingJsonFromBucket(bool $sync = false): bool
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Booking import is disabled (IMPORT_JSON_ENABLED=false).');

            return false;
        }

        app(ImportBookingService::class)->importFromBucket($sync);

        return true;
    }

    /** Fetches new booking files from the digital hatch FTP into the bucket, then imports them. @param bool $force re-fetch already-taken files @return bool the run happened (false = HATCH_ENABLED=false or another run working); throws when anything failed */
    protected function importBookingJsonFromFtp(bool $force = false, bool $sync = false): bool
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Booking import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return false;
        }

        return app(DigitalHatchService::class)->importBookings($force, $sync);
    }
}

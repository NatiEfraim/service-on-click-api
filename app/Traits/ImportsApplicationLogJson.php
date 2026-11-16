<?php

namespace App\Traits;

use App\Services\DigitalHatchService;
use App\Services\ImportApplicationLogService;
use Illuminate\Support\Facades\Log;

trait ImportsApplicationLogJson
{
    /** Imports application log files waiting in the bucket ($sync saves inline, else chunk jobs are queued; a file with pending bookings stays in place). @return bool the import ran (false = disabled); throws when anything failed */
    protected function importApplicationLogJsonFromBucket(bool $sync = false): bool
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Application log import is disabled (IMPORT_JSON_ENABLED=false).');

            return false;
        }

        app(ImportApplicationLogService::class)->importFromBucket($sync);

        return true;
    }

    /** Fetches new application log files from the digital hatch FTP into the bucket, then imports them. @param bool $force re-fetch already-taken files @return bool the run happened (false = HATCH_ENABLED=false or another run working); throws when anything failed */
    protected function importApplicationLogJsonFromFtp(bool $force = false, bool $sync = false): bool
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Application log import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return false;
        }

        return app(DigitalHatchService::class)->importApplicationLogs($force, $sync);
    }
}

<?php

namespace App\Traits;

use App\Services\ImportBookingService;
use Illuminate\Support\Facades\Log;

trait ImportsBookingJson
{
    /** Queue the booking files waiting in import-json/booking-data if enabled; with $sync the chunk jobs run right now instead (see ReadsImportJsonFiles::importFromBucket). @param bool $sync @return array */
    protected function importBookingJson(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Booking import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['disabled' => true, 'busy' => false, 'files' => [], 'errors' => []];
        }

        return app(ImportBookingService::class)->importFromBucket($sync);
    }
}

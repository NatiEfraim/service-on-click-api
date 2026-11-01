<?php

namespace App\Traits;

use App\Services\ImportBookingService;
use Illuminate\Support\Facades\Log;

trait ImportsBookingJson
{
    /** Imports waiting booking files when enabled; $sync runs the chunk jobs inline. */
    protected function importBookingJson(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Booking import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['disabled' => true, 'busy' => false, 'files' => [], 'errors' => []];
        }

        return app(ImportBookingService::class)->importFromBucket($sync);
    }
}

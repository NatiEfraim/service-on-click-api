<?php

namespace App\Traits;

use App\Services\ImportApplicationLogService;
use Illuminate\Support\Facades\Log;

trait ImportsApplicationLogJson
{
    /** Imports waiting application log files when enabled; $sync runs the chunk jobs inline. */
    protected function importApplicationLogJson(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Application log import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['disabled' => true, 'busy' => false, 'files' => [], 'errors' => []];
        }

        return app(ImportApplicationLogService::class)->importFromBucket($sync);
    }
}

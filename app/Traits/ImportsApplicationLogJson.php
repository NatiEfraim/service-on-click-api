<?php

namespace App\Traits;

use App\Services\ImportApplicationLogService;
use Illuminate\Support\Facades\Log;

trait ImportsApplicationLogJson
{
    /** Queue the application log files waiting in import-json/application-log if enabled; with $sync the chunk jobs run right now instead (see ReadsImportJsonFiles::importFromBucket). @param bool $sync @return array */
    protected function importApplicationLogJson(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Application log import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['disabled' => true, 'busy' => false, 'files' => [], 'errors' => []];
        }

        return app(ImportApplicationLogService::class)->importFromBucket($sync);
    }
}

<?php

namespace App\Traits;

use App\Services\DigitalHatchService;
use App\Services\ImportApplicationLogService;
use Illuminate\Support\Facades\Log;

trait ImportsApplicationLogJson
{
    /** Imports waiting application log files when enabled; $sync runs the chunk jobs inline. (What GET /api/test-import runs.) */
    protected function importApplicationLogJson(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Application log import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['disabled' => true, 'busy' => false, 'files' => [], 'errors' => []];
        }

        return app(ImportApplicationLogService::class)->importFromBucket($sync);
    }

    /** Reads application log files from the bucket and queues chunk jobs ($sync saves inline; a file with pending bookings stays in place). @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>} */
    protected function importApplicationLogJsonFromBucket(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Application log import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'IMPORT_JSON_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => ['imported' => app(ImportApplicationLogService::class)->importFromBucket($sync)]];
    }

    /** Fetches new application log files from the digital hatch FTP into the bucket, then imports them. Off while HATCH_ENABLED=false. @param bool $force re-fetch already-taken files @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>} */
    protected function importApplicationLogJsonFromFtp(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Application log import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'HATCH_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => app(DigitalHatchService::class)->importApplicationLogs($force, $sync)];
    }
}

<?php

namespace App\Traits;

use App\Jobs\ImportApplicationLogJsonBucketJob;
use App\Jobs\ImportApplicationLogJsonFtpJob;
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

    /**
     * importApplicationLogJson:bucket - queues ImportApplicationLogJsonBucketJob (a queue worker runs it), or with $sync imports the files waiting
     * in the bucket here, rows included, and returns what it did.
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importApplicationLogJsonFromBucket(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Application log import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'IMPORT_JSON_ENABLED=false'];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => ['imported' => app(ImportApplicationLogService::class)->importFromBucket(true)]];
        }

        dispatch(new ImportApplicationLogJsonBucketJob());

        return ['enabled' => true, 'queued' => true];
    }

    /**
     * importApplicationLogJson:ftp - queues ImportApplicationLogJsonFtpJob, or with $sync takes the new application log files from the digital
     * hatch into the bucket and imports them here. Off while HATCH_ENABLED=false.
     * @param bool $force take the files again even when they were taken before
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importApplicationLogJsonFromFtp(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Application log import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'HATCH_ENABLED=false'];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => app(DigitalHatchService::class)->importApplicationLogs($force, true)];
        }

        dispatch(new ImportApplicationLogJsonFtpJob($force));

        return ['enabled' => true, 'queued' => true];
    }
}

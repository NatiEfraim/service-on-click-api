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

    /**
     * importApplicationLogJson:bucket - the command reads the application log files waiting in the bucket (ImportApplicationLogService), queues
     * their rows as chunk jobs (ImportApplicationLogJsonChunkJob: a queue worker saves them, a log waits for its booking), moves each file to
     * loaded-json and returns what it did. With $sync the rows are saved here instead, so no worker is needed; a file with logs whose booking is
     * not saved yet then stays where it is.
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importApplicationLogJsonFromBucket(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('Application log import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'IMPORT_JSON_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => ['imported' => app(ImportApplicationLogService::class)->importFromBucket($sync)]];
    }

    /**
     * importApplicationLogJson:ftp - the command takes the new application log files from the digital hatch (the FTP, or the local test folder) into
     * the bucket and imports them like importApplicationLogJson:bucket: command -> this trait -> DigitalHatchService -> the rows queued as chunk
     * jobs (or saved here with $sync), the file moved to loaded-json, its name noted in digital_hatch_fetched_files. Off while HATCH_ENABLED=false.
     * @param bool $force take the files again even when they were taken before
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
    protected function importApplicationLogJsonFromFtp(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('Application log import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'HATCH_ENABLED=false'];
        }

        return ['enabled' => true, 'queued' => false, 'result' => app(DigitalHatchService::class)->importApplicationLogs($force, $sync)];
    }
}

<?php

namespace App\Traits;

use App\Jobs\ImportFileBucketJob;
use App\Jobs\ImportFileFtpJob;
use App\Services\DigitalHatchService;
use App\Services\ImportFileService;
use Illuminate\Support\Facades\Log;

/** Imports SrvOnClickFiles_* attachments: copies them from import-files to the live folder, links them to their booking rows, moves them to loaded-files. */
trait ImportsFile
{
    /** Saves the received files and images when enabled. (What GET /api/test-import runs, after the bookings, because a file belongs to a booking.) */
    protected function importFile(): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('File import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['disabled' => true, 'busy' => false, 'errors' => []];
        }

        return app(ImportFileService::class)->importFromBucket();
    }

    /** Queues ImportFileBucketJob, or with $sync saves bucket files inline. @return array{enabled: bool, switch?: string, queued?: bool, job?: class-string, result?: array<string, mixed>} */
    protected function importFileFromBucket(bool $sync = false): array
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('File import is disabled (IMPORT_JSON_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'IMPORT_JSON_ENABLED=false'];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => ['imported' => app(ImportFileService::class)->importFromBucket()]];
        }

        dispatch(new ImportFileBucketJob());

        return ['enabled' => true, 'queued' => true, 'job' => ImportFileBucketJob::class];
    }

    /** Queues ImportFileFtpJob, or with $sync fetches files from the digital hatch FTP and saves them inline. Off while HATCH_ENABLED=false. @param bool $force re-fetch already-taken files @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>} */
    protected function importFileFromFtp(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('File import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false, 'switch' => 'HATCH_ENABLED=false'];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => app(DigitalHatchService::class)->importFiles($force)];
        }

        dispatch(new ImportFileFtpJob($force));

        return ['enabled' => true, 'queued' => true, 'job' => ImportFileFtpJob::class];
    }
}

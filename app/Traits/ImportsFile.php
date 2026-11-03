<?php

namespace App\Traits;

use App\Jobs\ImportFileBucketJob;
use App\Jobs\ImportFileFtpJob;
use App\Services\DigitalHatchService;
use App\Services\ImportFileService;
use Illuminate\Support\Facades\Log;

/**
 * The files and images of the bookings (SrvOnClickFiles_<stored name>.<ext>): the bytes wait in one bucket folder (import-files) and are saved
 * in the database - linked to the rows the booking json created - copied to the folder the bookings serve them from and moved to loaded-files.
 */
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

    /**
     * importFile:bucket - queues ImportFileBucketJob (a queue worker runs it; the files are copied one by one, which can take a while), or with
     * $sync saves the files waiting in the bucket here and returns what it did.
     * @return array{enabled: bool, switch?: string, queued?: bool, job?: class-string, result?: array<string, mixed>}
     */
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

    /**
     * importFile:ftp - queues ImportFileFtpJob, or with $sync takes the new files and images from the digital hatch into the bucket and saves them
     * here. Off while HATCH_ENABLED=false.
     * @param bool $force take the files again even when they were taken before
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
     */
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

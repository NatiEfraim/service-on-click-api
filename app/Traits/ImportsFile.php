<?php

namespace App\Traits;

use App\Jobs\ImportFileBucketJob;
use App\Jobs\ImportFileFtpJob;
use App\Services\DigitalHatchService;
use App\Services\ImportFileService;
use Illuminate\Support\Facades\Log;

/** The files and images of the bookings (SrvOnClickFiles_<stored name>.<ext>): the bytes sit in one bucket folder and are linked to the rows the booking json created. */
trait ImportsFile
{
    /**
     * importFile:bucket - queues ImportFileBucketJob (a queue worker runs it), or with $sync links the files in the bucket folder here and returns
     * what it did.
     * @return array{enabled: bool, switch?: string, queued?: bool, result?: array<string, mixed>}
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

        return ['enabled' => true, 'queued' => true];
    }

    /**
     * importFile:ftp - queues ImportFileFtpJob, or with $sync takes the new files and images from the digital hatch into the bucket folder and
     * links them here. Off while HATCH_ENABLED=false.
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

        return ['enabled' => true, 'queued' => true];
    }
}

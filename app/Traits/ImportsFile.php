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
    /** Saves bucket attachments inline with $sync, else queues ImportFileBucketJob. @return bool ran inline to the end (false = disabled or queued); throws when anything failed */
    protected function importFileFromBucket(bool $sync = false): bool
    {
        if (!config('app.import_json.enabled', true)) {
            Log::info('File import is disabled (IMPORT_JSON_ENABLED=false).');

            return false;
        }

        if (!$sync) {
            dispatch(new ImportFileBucketJob());

            return false;
        }

        app(ImportFileService::class)->importFromBucket();

        return true;
    }

    /** Fetches attachments from the digital hatch FTP and saves them inline with $sync, else queues ImportFileFtpJob. @param bool $force re-fetch already-taken files @return bool ran inline to the end (false = disabled, queued or another run working); throws when anything failed */
    protected function importFileFromFtp(bool $force = false, bool $sync = false): bool
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('File import from the digital hatch is disabled (HATCH_ENABLED=false).');

            return false;
        }

        if (!$sync) {
            dispatch(new ImportFileFtpJob($force));

            return false;
        }

        return app(DigitalHatchService::class)->importFiles($force);
    }
}

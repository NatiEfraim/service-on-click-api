<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Jobs\ImportApplicationLogJsonBucketJob;
use App\Traits\ImportsApplicationLogJson;

class ImportApplicationLogJsonBucketCommand extends ImportCommand
{
    use ImportsApplicationLogJson;

    protected $signature = 'importApplicationLogJson:bucket
        {--sync : Import here and show the result, instead of queueing a job (no queue worker needed)}';

    /** The name this command had before it was split by where the files come from. */
    protected $aliases = ['importApplicationLogJson:mysql'];

    protected $description = 'Read the application log files (SrvOnClick_b2r_application_logs_*) waiting in the bucket folder import-json, save the logs in the database chunk by chunk, and move each file to loaded-json';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importApplicationLogJsonFromBucket($sync);
    }

    protected function jobClass(): string
    {
        return ImportApplicationLogJsonBucketJob::class;
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::APPLICATION_LOG;
    }
}

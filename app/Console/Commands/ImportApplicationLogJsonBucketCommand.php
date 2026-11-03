<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Traits\ImportsApplicationLogJson;

class ImportApplicationLogJsonBucketCommand extends ImportCommand
{
    use ImportsApplicationLogJson;

    protected $signature = 'importApplicationLogJson:bucket
        {--sync : Save the rows here and show the result, instead of queueing them as chunk jobs (no queue worker needed)}';

    /** The name this command had before it was split by where the files come from. */
    protected $aliases = ['importApplicationLogJson:mysql'];

    protected $description = 'Read the application log files (SrvOnClick_b2r_application_logs_*) waiting in the bucket folder import-json, queue their rows as ImportApplicationLogJsonChunkJob jobs that save the logs in the database (a log waits for its booking), and move each file to loaded-json';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importApplicationLogJsonFromBucket($sync);
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::APPLICATION_LOG;
    }
}

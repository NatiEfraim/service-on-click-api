<?php

namespace App\Console\Commands;

use App\Jobs\ImportFileBucketJob;
use App\Traits\ImportsFile;

class ImportFileBucketCommand extends ImportCommand
{
    use ImportsFile;

    protected $signature = 'importFile:bucket
        {--sync : Import here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Link the files and images the bookings name to their bytes in the bucket folder (import-files, SrvOnClickFiles_<stored name>.<ext>); nothing is moved or deleted';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importFileFromBucket($sync);
    }

    protected function jobClass(): string
    {
        return ImportFileBucketJob::class;
    }
}

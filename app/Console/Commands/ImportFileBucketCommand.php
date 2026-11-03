<?php

namespace App\Console\Commands;

use App\Traits\ImportsFile;

class ImportFileBucketCommand extends ImportCommand
{
    use ImportsFile;

    protected $signature = 'importFile:bucket
        {--sync : Import here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Save the files and images the bookings name: take the SrvOnClickFiles_<stored name>.<ext> files waiting in the bucket (import-files), link each to its booking\'s file / image in the database, copy it to files, and move the received file to loaded-files';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importFileFromBucket($sync);
    }
}

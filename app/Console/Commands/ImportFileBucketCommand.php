<?php

namespace App\Console\Commands;

use App\Traits\ImportsFile;

class ImportFileBucketCommand extends ImportCommand
{
    use ImportsFile;

    protected $signature = 'importFile:bucket
        {--sync : Import synchronously (no queue worker needed)}';

    protected $description = 'Save b2r attachment files waiting in the bucket (import-files → database → files, file → loaded-files).';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importFileFromBucket($sync);
    }
}

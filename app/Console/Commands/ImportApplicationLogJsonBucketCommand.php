<?php

namespace App\Console\Commands;

use App\Traits\ImportsApplicationLogJson;

class ImportApplicationLogJsonBucketCommand extends ImportCommand
{
    use ImportsApplicationLogJson;

    protected $signature = 'importApplicationLogJson:bucket
        {--sync : Import synchronously (no queue worker needed)}';

    /** The name this command had before it was split by where the files come from. */
    protected $aliases = ['importApplicationLogJson:mysql'];

    protected $description = 'Import b2r application log json files waiting in the bucket (import-json → chunk jobs → database, file → loaded-json).';

    protected function importFrom(bool $force, bool $sync): bool
    {
        return $this->importApplicationLogJsonFromBucket($sync);
    }
}

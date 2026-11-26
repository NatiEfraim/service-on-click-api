<?php

namespace App\Console\Commands;

use App\Traits\ImportsBookingJson;

class ImportBookingJsonBucketCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:bucket
        {--sync : Import synchronously (no queue worker needed)}';

    /** The name this command had before it was split by where the files come from. */
    protected $aliases = ['importBookingJson:mysql'];

    protected $description = 'Import b2r booking json files waiting in the bucket (import-json → chunk jobs → database, file → loaded-json); importFile:bucket and importApplicationLogJson:bucket run on their own.';

    protected function importFrom(bool $force, bool $sync): bool
    {
        return $this->importBookingJsonFromBucket($sync);
    }
}

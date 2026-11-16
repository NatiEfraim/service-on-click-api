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

    protected $description = 'Import b2r booking json files waiting in the bucket (import-json → chunk jobs → database, file → loaded-json); a clean run then imports the attachment files (importFile:bucket) and then the application logs (importApplicationLogJson:bucket).';

    protected function importFrom(bool $force, bool $sync): bool
    {
        return $this->importBookingJsonFromBucket($sync);
    }

    /** Bookings first, then their attachment files, then the application logs that reference them. @return array<int, string> */
    protected function followUpCommands(): array
    {
        return ['importFile:bucket', 'importApplicationLogJson:bucket'];
    }
}

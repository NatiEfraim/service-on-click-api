<?php

namespace App\Console\Commands;

use App\Traits\ImportsBookingJson;

class ImportBookingJsonFtpCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:ftp
        {--force : Re-take files already noted in digital_hatch_fetched_files}
        {--sync : Import synchronously (no queue worker needed)}';

    protected $description = 'Take new b2r booking json files from the FTP into the bucket and import them (importFile:ftp and importApplicationLogJson:ftp run on their own schedules).';

    protected function importFrom(bool $force, bool $sync): bool
    {
        return $this->importBookingJsonFromFtp($force, $sync);
    }
}

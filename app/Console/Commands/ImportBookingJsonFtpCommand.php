<?php

namespace App\Console\Commands;

use App\Traits\ImportsBookingJson;

class ImportBookingJsonFtpCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:ftp
        {--force : Re-take files already noted in digital_hatch_fetched_files}
        {--sync : Import synchronously (no queue worker needed)}';

    protected $description = 'Take new b2r booking json files from the FTP into the bucket and import them; a clean run then takes the attachment files (importFile:ftp) and then the application logs (importApplicationLogJson:ftp).';

    protected function importFrom(bool $force, bool $sync): bool
    {
        return $this->importBookingJsonFromFtp($force, $sync);
    }

    /** Bookings first, then their attachment files, then the application logs that reference them. @return array<int, string> */
    protected function followUpCommands(): array
    {
        return ['importFile:ftp', 'importApplicationLogJson:ftp'];
    }
}

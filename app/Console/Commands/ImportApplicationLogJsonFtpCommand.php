<?php

namespace App\Console\Commands;

use App\Traits\ImportsApplicationLogJson;

class ImportApplicationLogJsonFtpCommand extends ImportCommand
{
    use ImportsApplicationLogJson;

    protected $signature = 'importApplicationLogJson:ftp
        {--force : Re-take files already noted in digital_hatch_fetched_files}
        {--sync : Import synchronously (no queue worker needed)}';

    protected $description = 'Take new b2r application log json files from the FTP into the bucket and import them.';

    protected function importFrom(bool $force, bool $sync): bool
    {
        return $this->importApplicationLogJsonFromFtp($force, $sync);
    }
}

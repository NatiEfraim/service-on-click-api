<?php

namespace App\Console\Commands;

use App\Traits\ImportsFile;

class ImportFileFtpCommand extends ImportCommand
{
    use ImportsFile;

    protected $signature = 'importFile:ftp
        {--force : Re-take files already noted in digital_hatch_fetched_files}
        {--sync : Import synchronously (no queue worker needed)}';

    protected $description = 'Take new b2r attachment files from the FTP into the bucket and save them like importFile:bucket.';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importFileFromFtp($force, $sync);
    }
}

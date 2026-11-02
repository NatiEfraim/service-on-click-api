<?php

namespace App\Console\Commands;

use App\Jobs\ImportFileFtpJob;
use App\Traits\ImportsFile;

class ImportFileFtpCommand extends ImportCommand
{
    use ImportsFile;

    protected $signature = 'importFile:ftp
        {--force : Take the files again even if they were taken before (digital_hatch_fetched_files is ignored)}
        {--sync : Import here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Take the new SrvOnClickFiles_<stored name>.<ext> files from the digital hatch (the FTP, or DIGITAL_HATCH_IMPORT_FILES_PATH when it is set) into the bucket folder import-files, and link them to the bookings that name them';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importFileFromFtp($force, $sync);
    }

    protected function jobClass(): string
    {
        return ImportFileFtpJob::class;
    }
}

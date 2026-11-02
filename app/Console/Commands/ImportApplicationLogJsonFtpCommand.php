<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Jobs\ImportApplicationLogJsonFtpJob;
use App\Traits\ImportsApplicationLogJson;

class ImportApplicationLogJsonFtpCommand extends ImportCommand
{
    use ImportsApplicationLogJson;

    protected $signature = 'importApplicationLogJson:ftp
        {--force : Take the files again even if they were taken before (digital_hatch_fetched_files is ignored)}
        {--sync : Import here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Take the new SrvOnClick_b2r_application_logs_* files from the digital hatch (the FTP, or DIGITAL_HATCH_IMPORT_PATH when it is set) into the bucket, save the logs in the database, and move each file to loaded-json';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importApplicationLogJsonFromFtp($force, $sync);
    }

    protected function jobClass(): string
    {
        return ImportApplicationLogJsonFtpJob::class;
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::APPLICATION_LOG;
    }
}

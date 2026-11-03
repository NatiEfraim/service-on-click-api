<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Traits\ImportsApplicationLogJson;

class ImportApplicationLogJsonFtpCommand extends ImportCommand
{
    use ImportsApplicationLogJson;

    protected $signature = 'importApplicationLogJson:ftp
        {--force : Take the files again even if they were taken before (digital_hatch_fetched_files is ignored)}
        {--sync : Save the rows here and show the result, instead of queueing them as chunk jobs (no queue worker needed)}';

    protected $description = 'Take the new SrvOnClick_b2r_application_logs_* files from the digital hatch (the FTP, or DIGITAL_HATCH_IMPORT_PATH when it is set) into the bucket, queue their rows as ImportApplicationLogJsonChunkJob jobs that save the logs in the database (a log waits for its booking), move each file to loaded-json and note it in digital_hatch_fetched_files';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importApplicationLogJsonFromFtp($force, $sync);
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::APPLICATION_LOG;
    }
}

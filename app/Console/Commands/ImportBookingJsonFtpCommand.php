<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Jobs\ImportBookingJsonFtpJob;
use App\Traits\ImportsBookingJson;

class ImportBookingJsonFtpCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:ftp
        {--force : Take the files again even if they were taken before (digital_hatch_fetched_files is ignored)}
        {--sync : Import here and show the result, instead of queueing a job (no queue worker needed)}';

    protected $description = 'Take the new SrvOnClick_b2r_export_bookings_* files from the digital hatch (the FTP, or DIGITAL_HATCH_IMPORT_PATH when it is set) into the bucket, save the bookings in the database, and move each file to loaded-json';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importBookingJsonFromFtp($force, $sync);
    }

    protected function jobClass(): string
    {
        return ImportBookingJsonFtpJob::class;
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::EXPORT_BOOKING;
    }
}

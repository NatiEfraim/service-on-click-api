<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Traits\ImportsBookingJson;

class ImportBookingJsonFtpCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:ftp
        {--force : Take the files again even if they were taken before (digital_hatch_fetched_files is ignored)}
        {--sync : Save the rows here and show the result, instead of queueing them as chunk jobs (no queue worker needed)}';

    protected $description = 'Take the new SrvOnClick_b2r_export_bookings_* files from the digital hatch (the FTP, or DIGITAL_HATCH_IMPORT_PATH when it is set) into the bucket, queue their rows as ImportBookingJsonChunkJob jobs that save the bookings in the database, move each file to loaded-json and note it in digital_hatch_fetched_files';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importBookingJsonFromFtp($force, $sync);
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::EXPORT_BOOKING;
    }
}

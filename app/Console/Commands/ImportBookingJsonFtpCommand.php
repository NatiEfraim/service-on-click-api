<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Traits\ImportsBookingJson;

class ImportBookingJsonFtpCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:ftp
        {--force : Re-take files already noted in digital_hatch_fetched_files}
        {--sync : Import synchronously (no queue worker needed)}';

    protected $description = 'Take new b2r booking json files from the FTP into the bucket and import them.';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importBookingJsonFromFtp($force, $sync);
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::EXPORT_BOOKING;
    }
}

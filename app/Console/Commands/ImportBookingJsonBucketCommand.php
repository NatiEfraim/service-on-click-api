<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Traits\ImportsBookingJson;

class ImportBookingJsonBucketCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:bucket
        {--sync : Save the rows here and show the result, instead of queueing them as chunk jobs (no queue worker needed)}';

    /** The name this command had before it was split by where the files come from. */
    protected $aliases = ['importBookingJson:mysql'];

    protected $description = 'Read the booking files (SrvOnClick_b2r_export_bookings_*) waiting in the bucket folder import-json, queue their rows as ImportBookingJsonChunkJob jobs that save the bookings in the database, and move each file to loaded-json';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importBookingJsonFromBucket($sync);
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::EXPORT_BOOKING;
    }
}

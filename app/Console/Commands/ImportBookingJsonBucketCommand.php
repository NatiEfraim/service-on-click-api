<?php

namespace App\Console\Commands;

use App\Enums\JsonModelTypeEnum;
use App\Traits\ImportsBookingJson;

class ImportBookingJsonBucketCommand extends ImportCommand
{
    use ImportsBookingJson;

    protected $signature = 'importBookingJson:bucket
        {--sync : Import synchronously (no queue worker needed)}';

    /** The name this command had before it was split by where the files come from. */
    protected $aliases = ['importBookingJson:mysql'];

    protected $description = 'Import b2r booking json files waiting in the bucket (import-json → chunk jobs → database, file → loaded-json).';

    protected function importFrom(bool $force, bool $sync): array
    {
        return $this->importBookingJsonFromBucket($sync);
    }

    protected function modelType(): JsonModelTypeEnum
    {
        return JsonModelTypeEnum::EXPORT_BOOKING;
    }
}

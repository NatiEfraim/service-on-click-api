<?php

namespace App\Jobs;

use App\Services\ImportBookingService;

/** importBookingJson:bucket - reads the booking files waiting in the bucket, queues their rows and moves each file to loaded-json. */
class ImportBookingJsonBucketJob extends ImportJob
{
    protected function import(): array
    {
        return ['imported' => app(ImportBookingService::class)->importFromBucket()];
    }
}

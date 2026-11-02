<?php

namespace App\Jobs;

use App\Services\ImportApplicationLogService;

/** importApplicationLogJson:bucket - reads the application log files waiting in the bucket, queues their rows and moves each file to loaded-json. */
class ImportApplicationLogJsonBucketJob extends ImportJob
{
    protected function import(): array
    {
        return ['imported' => app(ImportApplicationLogService::class)->importFromBucket()];
    }
}

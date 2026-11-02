<?php

namespace App\Jobs;

use App\Services\ImportFileService;

/** importFile:bucket - links the files and images the bookings name to their bytes in the bucket folder (ImportFileService). */
class ImportFileBucketJob extends ImportJob
{
    protected function import(): array
    {
        return ['imported' => app(ImportFileService::class)->importFromBucket()];
    }
}

<?php

namespace App\Jobs;

use App\Services\StorageService;
use App\Services\UsersExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Writes the r2b users file to the bucket: UsersExportService::exportToBucket. */
class ExportUsersJsonBucketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct()
    {
        $this->tries = (int) config('app.jobs.digital_hatch_send_users.tries');
        $this->backoff = (int) config('app.jobs.digital_hatch_send_users.backoff');
        $this->timeout = (int) config('app.jobs.digital_hatch_send_users.timeout');
    }

    public function handle(): void
    {
        try {
            $result = app(UsersExportService::class)->exportToBucket();

            if (($result['errors'] ?? []) !== []) {
                throw new \RuntimeException(implode('; ', $result['errors']));
            }
        } catch (\Exception $e) {
            Log::error('ExportUsersJsonBucketJob: failed, will retry: ' . $e->getMessage());
            throw $e;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

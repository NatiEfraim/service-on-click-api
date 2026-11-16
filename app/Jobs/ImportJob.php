<?php

namespace App\Jobs;

use App\Services\StorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Base job for the two file-import jobs (importFile:bucket and importFile:ftp). */
abstract class ImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct()
    {
        $this->tries = (int) config('app.jobs.import_job.tries');
        $this->backoff = (int) config('app.jobs.import_job.backoff');
        $this->timeout = (int) config('app.jobs.import_job.timeout');
    }

    /** Does the import; logs its own details and throws on any failure. */
    abstract protected function import(): void;

    /** Runs import(); a throw is logged and rethrown so the queue retries after back-off. */
    public function handle(): void
    {
        try {
            $this->import();
        } catch (\Exception $e) {
            Log::error(static::class . ': failed, will retry: ' . $e->getMessage());

            throw $e;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

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

    /** @return array<string, mixed> */
    abstract protected function import(): array;

    /** Runs import(); throws on any error so the queue retries after back-off. @return array<string, mixed> */
    public function handle(): array
    {
        try {
            $result = $this->import();

            $errors = [];

            foreach ([...($result['errors'] ?? []), ...($result['imported']['errors'] ?? [])] as $error) {
                $errors[] = is_array($error) ? "{$error['file']}: {$error['message']}" : (string) $error;
            }

            if ($errors !== []) {
                throw new \RuntimeException(implode('; ', $errors));
            }

            return $result;
        } catch (\Exception $e) {
            Log::error(static::class . ': failed, will retry: ' . $e->getMessage());
            throw $e;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

<?php

namespace App\Jobs;

use App\Services\StorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * What the six jobs of the import commands share (importBookingJson, importApplicationLogJson and importFile, each ":bucket" or ":ftp"): the
 * command only queues its job, the job does the import. A ":bucket" job reads the bucket folder and queues the rows as chunk jobs; a ":ftp" job
 * first takes the new files from the digital hatch into that folder. Tries, back-off and timeout are config('app.jobs.import_job').
 */
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

    /** Does the import. @return array<string, mixed> what the service returned: "errors" (taking the files) and "imported" (the bucket import) say what went wrong */
    abstract protected function import(): array;

    /** The FTP may be down, a file may not be readable yet: a run with errors throws, so the queue tries again after the back-off. @return array<string, mixed> */
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

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
 * What the two jobs of the file import share (importFile:bucket and importFile:ftp): the command only queues its job and the job does the import,
 * because copying files takes a while; a ":ftp" job first takes the new files from the digital hatch into the bucket folder. (The json imports
 * have no such job: their command reads the files itself and queues the rows as chunk jobs, ImportBookingJsonChunkJob and
 * ImportApplicationLogJsonChunkJob.) Tries, back-off and timeout are config('app.jobs.import_job').
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

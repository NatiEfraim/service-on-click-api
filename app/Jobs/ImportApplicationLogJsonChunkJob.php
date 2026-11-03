<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportApplicationLogJsonChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private readonly array $logsChunk;
    private readonly int $chunkIndex;

    public int $tries;

    /** Seconds before retrying, or before looking again for a booking that is not saved yet. */
    public int $backoff;

    public int $timeout;

    public function __construct(
        array $logsChunk,
        int $chunkIndex)
    {
        $this->logsChunk = $logsChunk;
        $this->chunkIndex = $chunkIndex;
        $this->tries = (int) config('app.jobs.import_application_log_json.tries');
        $this->backoff = (int) config('app.jobs.import_application_log_json.backoff');
        $this->timeout = (int) config('app.jobs.import_application_log_json.timeout');
    }

    /**
     * A log whose booking is not saved yet (its chunk may still be queued) makes the job wait and look again; the logs that were saved are skipped
     * next time. When the tries are used up and some logs still have no booking the job FAILS, so it shows in failed_jobs with its rows and
     * `php artisan queue:retry all` saves them once the bookings are in - they are never dropped quietly.
     * @return array<string, int>
     */
    public function handle(): array
    {
        try {
            $stats = app(\App\Services\ImportApplicationLogService::class)
                ->importChunk($this->logsChunk, $this->chunkIndex);

            if ($stats['booking_not_found'] > 0) {
                if ($this->attempts() >= $this->tries) {
                    throw new \RuntimeException(
                        "{$stats['booking_not_found']} logs of chunk {$this->chunkIndex} still have no booking after {$this->tries} tries: import the bookings, then run `php artisan queue:retry all`."
                    );
                }

                Log::info("ImportApplicationLogJsonChunkJob: chunk {$this->chunkIndex} has {$stats['booking_not_found']} logs whose booking is not saved yet, looking again in {$this->backoff} seconds.");
                $this->release($this->backoff);
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error("ImportApplicationLogJsonChunkJob: chunk {$this->chunkIndex} failed: " . $e->getMessage());
            throw $e;
        } finally {
            app(\App\Services\StorageService::class)->logToS3();
        }
    }
}

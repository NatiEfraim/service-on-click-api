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

    /** Maximum number of attempts. */
    public int $tries;

    /** Seconds to wait before retrying after failure, or before looking again for a booking that is not saved yet. */
    public int $backoff;

    /** Maximum seconds this job may run. */
    public int $timeout;

    /** Create a new job instance. @param array $logsChunk, int $chunkIndex */
    public function __construct(
        array $logsChunk,
        int $chunkIndex)
    {
        //
        $this->logsChunk = $logsChunk;
        $this->chunkIndex = $chunkIndex;
        $this->tries = (int) config('app.jobs.import_application_log_json.tries');
        $this->backoff = (int) config('app.jobs.import_application_log_json.backoff');
        $this->timeout = (int) config('app.jobs.import_application_log_json.timeout');
    }

    /** Execute the job. A log whose booking is not saved yet (its chunk may still be waiting in the queue) makes the job wait and look again; the logs saved so far are skipped next time. @return array<string, int> what the chunk did */
    public function handle(): array
    {
        try {
            $stats = app(\App\Services\ImportApplicationLogService::class)
                ->importChunk($this->logsChunk, $this->chunkIndex);

            if ($stats['booking_not_found'] > 0 && $this->attempts() < $this->tries) {
                Log::info("ImportApplicationLogJsonChunkJob: chunk {$this->chunkIndex} has {$stats['booking_not_found']} logs whose booking is not saved yet, looking again in {$this->backoff} seconds.");
                $this->release($this->backoff);
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error("ImportApplicationLogJsonChunkJob: chunk {$this->chunkIndex} failed, will retry: " . $e->getMessage());
            throw $e;
        } finally {
            app(\App\Services\StorageService::class)->logToS3();
        }
    }
}

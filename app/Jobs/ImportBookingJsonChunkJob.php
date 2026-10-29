<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportBookingJsonChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private readonly array $bookingsChunk;
    private readonly int $chunkIndex;

    /** Maximum number of attempts. */
    public int $tries;

    /** Seconds to wait before retrying after failure. */
    public int $backoff;

    /** Maximum seconds this job may run. */
    public int $timeout;

    /** Create a new job instance. @param array $bookingsChunk, int $chunkIndex */
    public function __construct(
        array $bookingsChunk,
        int $chunkIndex)
    {
        //
        $this->bookingsChunk = $bookingsChunk;
        $this->chunkIndex = $chunkIndex;
        $this->tries = (int) config('app.jobs.import_booking_json.tries');
        $this->backoff = (int) config('app.jobs.import_booking_json.backoff');
        $this->timeout = (int) config('app.jobs.import_booking_json.timeout');
    }

    /** Execute the job. A row that fails is skipped; the job then fails as a whole and is tried again (the rows already saved are left as they are). @return array<string, int> what the chunk did */
    public function handle(): array
    {
        try {
            return app(\App\Services\ImportBookingService::class)
                ->importChunk($this->bookingsChunk, $this->chunkIndex);
        } catch (\Exception $e) {
            Log::error("ImportBookingJsonChunkJob: chunk {$this->chunkIndex} failed, will retry: " . $e->getMessage());
            throw $e;
        } finally {
            app(\App\Services\StorageService::class)->logToS3();
        }
    }
}

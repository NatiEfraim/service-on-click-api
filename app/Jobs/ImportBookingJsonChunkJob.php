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

    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct(
        array $bookingsChunk,
        int $chunkIndex)
    {
        $this->bookingsChunk = $bookingsChunk;
        $this->chunkIndex = $chunkIndex;
        $this->tries = (int) config('app.jobs.import_booking_json.tries');
        $this->backoff = (int) config('app.jobs.import_booking_json.backoff');
        $this->timeout = (int) config('app.jobs.import_booking_json.timeout');
    }

    /** A failing row is skipped, then the job fails as a whole and is retried (saved rows stay). @return array<string, int> */
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

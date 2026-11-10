<?php

namespace App\Jobs;

use App\Services\StorageService;
use App\Services\SystemReferencesExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Sends the system references file (settings with their values) to the digital hatch: SystemReferencesExportService::export. */
class SendHatchSystemReferencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $backoff;

    public int $timeout;

    /** @param bool $force send even when this exact content was sent before */
    public function __construct(public readonly bool $force = false)
    {
        $this->tries = (int) config('app.jobs.digital_hatch_send_system_references.tries');
        $this->backoff = (int) config('app.jobs.digital_hatch_send_system_references.backoff');
        $this->timeout = (int) config('app.jobs.digital_hatch_send_system_references.timeout');
    }

    /** The FTP may be down for a while: a run that failed throws, so the queue tries again after the back-off. @return array<string, mixed> */
    public function handle(): array
    {
        try {
            $result = app(SystemReferencesExportService::class)->export($this->force);

            if (($result['errors'] ?? []) !== []) {
                throw new \RuntimeException(implode('; ', $result['errors']));
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('SendHatchSystemReferencesJob: failed, will retry: ' . $e->getMessage());
            throw $e;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

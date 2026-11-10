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

/** Writes today's system references file to the bucket: SystemReferencesExportService::exportToBucket. */
class ExportSysRefJsonBucketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $backoff;

    public int $timeout;

    public function __construct()
    {
        $this->tries = (int) config('app.jobs.digital_hatch_send_system_references.tries');
        $this->backoff = (int) config('app.jobs.digital_hatch_send_system_references.backoff');
        $this->timeout = (int) config('app.jobs.digital_hatch_send_system_references.timeout');
    }

    /** @return array<string, mixed> */
    public function handle(): array
    {
        try {
            $result = app(SystemReferencesExportService::class)->exportToBucket();

            if (($result['errors'] ?? []) !== []) {
                throw new \RuntimeException(implode('; ', $result['errors']));
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('ExportSysRefJsonBucketJob: failed, will retry: ' . $e->getMessage());
            throw $e;
        } finally {
            app(StorageService::class)->logToS3();
        }
    }
}

<?php

namespace App\Traits;

use App\Jobs\SendHatchSystemReferencesJob;
use App\Services\SystemReferencesExportService;
use Illuminate\Support\Facades\Log;

trait ExportsHatchSystemReferences
{
    /** Sends the system references file to the digital hatch; queues SendHatchSystemReferencesJob or runs inline with $sync. @return array{enabled: bool, queued?: bool, result?: array<string, mixed>} */
    protected function exportHatchSystemReferences(bool $force = false, bool $sync = false): array
    {
        if (!config('services.digital-hatch.enabled')) {
            Log::info('System references file send is disabled (HATCH_ENABLED=false).');

            return ['enabled' => false];
        }

        if ($sync) {
            return ['enabled' => true, 'queued' => false, 'result' => app(SystemReferencesExportService::class)->export($force)];
        }

        dispatch(new SendHatchSystemReferencesJob($force));

        return ['enabled' => true, 'queued' => true];
    }
}

<?php

namespace App\Console\Commands;

use App\Services\WorkflowAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdvanceWorkflowCommand extends Command
{
    protected $signature = 'workflow:advance {--registry : ask the clearance registry now, whatever the throttle says}';

    protected $description = 'Route types: move the requests whose interview moment has passed on, and close the ones the clearance registry confirms (when enabled)';

    public function handle(WorkflowAutomationService $automation): int
    {
        try {
            $due = $automation->advanceDue();
            $this->info("Due transitions: {$due['moved']} moved of {$due['checked']} checked.");

            if ($this->option('registry') || $this->registryIsDue()) {
                $registry = $automation->closeGranted();
                $this->info("Registry: {$registry['moved']} closed of {$registry['checked']} checked" . (isset($registry['skipped']) ? " ({$registry['skipped']})." : '.'));
            } else {
                $this->line('Registry: not due yet.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('AdvanceWorkflowCommand: failed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** The registry is asked at most every workflow.clearance.registry_every_minutes; the due transitions run every time. */
    private function registryIsDue(): bool
    {
        $minutes = max(1, (int) config('workflow.clearance.registry_every_minutes', 60));

        return Cache::add('workflow:advance:registry-asked', now()->toIso8601String(), now()->addMinutes($minutes));
    }
}

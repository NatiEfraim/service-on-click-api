<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** What every digital hatch run needs first: the hatch is switched on, and only one run of a kind works at a time. */
trait GuardsDigitalHatchRuns
{
    public function enabled(): bool
    {
        return (bool) config('services.digital-hatch.enabled');
    }

    /** Runs $work under a named lock; false when the hatch is disabled or another run holds the lock (the work then did not run). @param callable(): void $work */
    private function lockedRun(string $name, callable $work): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $lock = Cache::lock("digital-hatch:{$name}", 600);

        if (!$lock->get()) {
            Log::info("Digital hatch {$name} skipped: another run is working.");

            return false;
        }

        try {
            $work();

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Runs $work under a named lock; returns early when the hatch is disabled or another run holds the lock. @param callable(): array<string, mixed> $work @return array<string, mixed> */
    private function locked(string $name, callable $work): array
    {
        if (!$this->enabled()) {
            return ['enabled' => false];
        }

        $lock = Cache::lock("digital-hatch:{$name}", 600);

        if (!$lock->get()) {
            Log::info("Digital hatch {$name} skipped: another run is working.");

            return ['enabled' => true, 'busy' => true];
        }

        try {
            return ['enabled' => true, 'busy' => false, ...$work()];
        } finally {
            $lock->release();
        }
    }

}

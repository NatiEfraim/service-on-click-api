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

    /**
     * Runs $work under a lock named $name, unless the hatch is off (HATCH_ENABLED=false) or another run of that name holds the lock.
     * The scheduler and a manual run therefore cannot overlap.
     * @param callable(): array<string, mixed> $work @return array<string, mixed> ['enabled' => false], ['enabled' => true, 'busy' => true] or ['enabled' => true, 'busy' => false, ...$work()]
     */
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

    /**
     * Builds one file into the bucket; a failure is logged and added to $errors, so the file that is already there can still go out.
     * @param callable(): array<string, mixed> $work @param array<int, string> $errors @return array<string, mixed>
     */
    private function building(string $what, callable $work, array &$errors): array
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            Log::error("DigitalHatch: {$what} error: {$e->getMessage()}");
            $errors[] = "{$what}: {$e->getMessage()}";

            return ['written' => false];
        }
    }
}

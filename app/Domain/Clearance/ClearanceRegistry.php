<?php

namespace App\Domain\Clearance;

/**
 * Where a person's clearance level is recorded (ותיקן). The clearance route closes a request on its own once the registry
 * shows the requested level - when workflow.clearance.auto_close is on and an implementation is bound (AppServiceProvider).
 */
interface ClearanceRegistry
{
    /**
     * The clearance level code (ClearanceLevel::*) the registry holds for this ID number; null when the person is unknown
     * or has no clearance. Throws when the registry cannot be reached, so the caller can tell "no change" from "down".
     */
    public function levelCodeFor(string $personalId): ?string;
}

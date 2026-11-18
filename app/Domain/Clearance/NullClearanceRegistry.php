<?php

namespace App\Domain\Clearance;

/** No registry connected yet: nothing is ever confirmed automatically; במ closes requests by hand. */
final class NullClearanceRegistry implements ClearanceRegistry
{
    public function levelCodeFor(string $personalId): ?string
    {
        return null;
    }
}

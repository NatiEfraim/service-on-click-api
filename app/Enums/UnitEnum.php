<?php

namespace App\Enums;

use App\Models\Unit;

enum UnitEnum: int
{
    case H  = 1;
    case N = 2;
    case M  = 3;
    case R = 4;

    /** Get this unit's DB id by its name. @return ?int */
    public function getUnitId(): ?int
    {
        return match ($this) {
            self::H => Unit::where('name', 'H')->value('id') ?? null,
            self::N => Unit::where('name', 'N')->value('id') ?? null,
            self::M => Unit::where('name', 'M')->value('id') ?? null,
            self::R => Unit::where('name', 'R')->value('id') ?? null,
        };
    }
}

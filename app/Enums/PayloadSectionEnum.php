<?php

namespace App\Enums;

enum PayloadSectionEnum:int
{
    //
    case NONE = 0;
    case INPUTS = 1;
    case SELECTS = 2;
    case ITEMS = 3;

    public function name(): string
    {
        return match ($this) {
            self::NONE => 'none',
            self::INPUTS => 'inputs',
            self::SELECTS => 'selects',
            self::ITEMS => 'items',
        };
    }
}

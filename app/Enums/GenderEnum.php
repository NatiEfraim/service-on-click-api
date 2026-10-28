<?php

namespace App\Enums;

enum GenderEnum: int
{
    //
    case NONE = 0;
    case MALE = 1;
    case FEMALE = 2;

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'none',
            self::MALE => 'male',
            self::FEMALE => 'female',
        };
    }
}

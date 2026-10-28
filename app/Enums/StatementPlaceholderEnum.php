<?php

namespace App\Enums;

enum StatementPlaceholderEnum: string
{
    case BARBER_CLEAN_TIME = 'clean_time';
    case BARBER_HAIRCUT_TIME = 'haircut_time';

    /** Get the system reference id for this placeholder. @return int */
    public function getSystemReferenceId(): int
    {
        return match ($this) {
            self::BARBER_CLEAN_TIME => SystemReferenceEnum::BARBER_CLEAN_TIME->getSystemReferenceId(),
            self::BARBER_HAIRCUT_TIME => SystemReferenceEnum::BARBER_HAIRCUT_TIME->getSystemReferenceId(),
        };
    }

    /** Get the JSON key to read from the system reference value. @return string */
    public function getJsonKey(): string
    {
        return $this->value;
    }

    /** Get the placeholder with braces as used in statements, e.g. "{clean_time}". @return string */
    public function getPlaceholder(): string
    {
        return '{' . $this->value . '}';
    }

    /** Get the default config fallback path. @return ?string */
    public function getConfigFallback(): ?string
    {
        return match ($this) {
            self::BARBER_CLEAN_TIME => 'app.booking_type.barber_booking.clean_time',
            self::BARBER_HAIRCUT_TIME => 'app.booking_type.barber_booking.haircut_time',
        };
    }
}

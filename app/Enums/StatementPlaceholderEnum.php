<?php

namespace App\Enums;

enum StatementPlaceholderEnum: string
{
    case BARBER_CLEAN_TIME = 'clean_time';
    case BARBER_HAIRCUT_TIME = 'haircut_time';

    public function getSystemReferenceId(): int
    {
        return match ($this) {
            self::BARBER_CLEAN_TIME => SystemReferenceEnum::BARBER_CLEAN_TIME->getSystemReferenceId(),
            self::BARBER_HAIRCUT_TIME => SystemReferenceEnum::BARBER_HAIRCUT_TIME->getSystemReferenceId(),
        };
    }

    /** JSON key read from the system reference value. */
    public function getJsonKey(): string
    {
        return $this->value;
    }

    /** The placeholder as written in statements, e.g. "{clean_time}". */
    public function getPlaceholder(): string
    {
        return '{' . $this->value . '}';
    }

    /** Config path used when the system reference has no value. */
    public function getConfigFallback(): ?string
    {
        return match ($this) {
            self::BARBER_CLEAN_TIME => 'app.booking_type.barber_booking.clean_time',
            self::BARBER_HAIRCUT_TIME => 'app.booking_type.barber_booking.haircut_time',
        };
    }
}

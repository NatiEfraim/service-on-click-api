<?php

namespace App\Enums;

/** When a booking type's statement is shown to the user (booking_type_has_statements.show_time). */
enum StatementShowTimeEnum: int
{
    case ON_FORM_OPEN = 0;

    case ON_SUBMIT = 1;

    public function name(): string
    {
        return match ($this) {
            self::ON_FORM_OPEN => 'on_form_open',
            self::ON_SUBMIT => 'on_submit',
        };
    }
}

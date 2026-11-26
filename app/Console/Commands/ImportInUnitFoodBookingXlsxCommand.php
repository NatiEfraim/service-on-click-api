<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportInUnitFoodBookingXlsxCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'importInUnitFoodBooking:xlsx' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Import in_unit_food_booking.xlsx: only these people keep the user permission of IN_UNIT_FOOD_BOOKING (plus the common infosec + ICT ones)';

    protected function bookingTypes(): array
    {
        return [BookingTypeEnum::IN_UNIT_FOOD_BOOKING];
    }

    protected function defaultFileName(): string
    {
        return 'in_unit_food_booking.xlsx';
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportOutUnitFoodTransportationUsersCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'users:import-out-unit-food-transportation' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Give the people in users_out_unit_food_booking_transportion_booking.xlsx (bucket import-xlsx) the user permissions of OUT_UNIT_FOOD_BOOKING and TRANSPORTATION_BOOKING plus the common infosec + ICT ones';

    protected function bookingTypes(): array
    {
        return [
            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING,
            BookingTypeEnum::TRANSPORTATION_BOOKING,
        ];
    }

    protected function defaultFileName(): string
    {
        // The original file keeps its misspelling; pass another name as the argument if it was fixed.
        return 'users_out_unit_food_booking_transportion_booking.xlsx';
    }
}

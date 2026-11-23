<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportCarBookingUsersCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'users:import-car-booking' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Give the people in users_car_booking.xlsx (bucket import-xlsx) the user permission of CAR_BOOKING plus the common infosec + ICT ones';

    protected function bookingTypes(): array
    {
        return [BookingTypeEnum::CAR_BOOKING];
    }

    protected function defaultFileName(): string
    {
        return 'users_car_booking.xlsx';
    }
}

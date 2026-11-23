<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportCarMaintenanceBookingUsersCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'users:import-car-maintenance-booking' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Give the people in users_car_maintanence_booking.xlsx (bucket import-xlsx) the user permission of CAR_MAINTENANCE_BOOKING plus the common infosec + ICT ones';

    protected function bookingTypes(): array
    {
        return [BookingTypeEnum::CAR_MAINTENANCE_BOOKING];
    }

    protected function defaultFileName(): string
    {
        // The original file keeps its misspelling; pass another name as the argument if it was fixed.
        return 'users_car_maintanence_booking.xlsx';
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportCarMaintenanceBookingXlsxCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'importCarMaintenanceBooking:xlsx' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Import car_maintenance_booking.xlsx: only these people keep the user permission of CAR_MAINTENANCE_BOOKING (plus the common infosec + ICT ones)';

    protected function bookingTypes(): array
    {
        return [BookingTypeEnum::CAR_MAINTENANCE_BOOKING];
    }

    protected function defaultFileName(): string
    {
        return 'car_maintenance_booking.xlsx';
    }
}

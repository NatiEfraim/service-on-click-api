<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportCarBookingXlsxCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'importCarBooking:xlsx' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Import car_booking_users.xlsx: only these people keep the user permission of CAR_BOOKING (plus the common infosec + ICT ones)';

    protected function bookingTypes(): array
    {
        return [BookingTypeEnum::CAR_BOOKING];
    }

    protected function defaultFileName(): string
    {
        return 'car_booking_users.xlsx';
    }
}

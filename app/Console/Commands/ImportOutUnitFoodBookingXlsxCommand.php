<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportOutUnitFoodBookingXlsxCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'importOutUnitFoodBooking:xlsx' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Import out_unit_food_booking.xlsx: only these people keep the user permission of OUT_UNIT_FOOD_BOOKING (plus the common infosec + ICT ones)';

    protected function bookingTypes(): array
    {
        return [BookingTypeEnum::OUT_UNIT_FOOD_BOOKING];
    }

    protected function defaultFileName(): string
    {
        return 'out_unit_food_booking.xlsx';
    }
}

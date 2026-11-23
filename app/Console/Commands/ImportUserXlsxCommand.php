<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportUserXlsxCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'importUser:xlsx' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Import users.xlsx: only these people keep the user permissions of equipment (male + female), barber, transportation, in-unit food and construction (plus the common infosec + ICT ones)';

    protected function bookingTypes(): array
    {
        // The file has no gender column, so everyone gets both equipment types (there is no generic equipment_booking row).
        return [
            BookingTypeEnum::EQUIPMENT_BOOKING_MALE,
            BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE,
            BookingTypeEnum::BARBER_BOOKING,
            BookingTypeEnum::TRANSPORTATION_BOOKING,
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING,
            BookingTypeEnum::CONSTRUCTION_BOOKING,
        ];
    }

    protected function defaultFileName(): string
    {
        return 'users.xlsx';
    }
}

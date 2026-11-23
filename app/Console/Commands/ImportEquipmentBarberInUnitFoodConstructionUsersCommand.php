<?php

namespace App\Console\Commands;

use App\Enums\BookingTypeEnum;

class ImportEquipmentBarberInUnitFoodConstructionUsersCommand extends ImportBookingTypeUsersCommand
{
    protected $signature = 'users:import-equipment-barber-in-unit-food-construction' . ImportBookingTypeUsersCommand::ARGS;

    protected $description = 'Give the people in users_equipment_barber_in_unit_food_construction_booking.xlsx (bucket import-xlsx) the user permissions of equipment (male + female), barber, in-unit food and construction plus the common infosec + ICT ones';

    protected function bookingTypes(): array
    {
        // The file has no gender column, so everyone gets both equipment types (there is no generic equipment_booking row).
        return [
            BookingTypeEnum::EQUIPMENT_BOOKING_MALE,
            BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE,
            BookingTypeEnum::BARBER_BOOKING,
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING,
            BookingTypeEnum::CONSTRUCTION_BOOKING,
        ];
    }

    protected function defaultFileName(): string
    {
        return 'users_equipment_barber_in_unit_food_construction_booking.xlsx';
    }
}

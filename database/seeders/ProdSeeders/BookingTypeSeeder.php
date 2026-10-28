<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeIconEnum;
use App\Enums\BookingTypePositionEnum;
use App\Models\BookingType;
use App\Models\GroupBookingType;
use App\Models\GroupBookingTypeHasBookingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class BookingTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * The ids are the ones the mobile app's BookingTypeSeeder produces (the outsourced link types -
     * weapon, kitbag, digital ticket, entry permission - are not part of the web system, so their ids
     * stay unused). Exported bookings carry booking_type_id, so the two systems must agree on them.
     */
    public function run(): void
    {
        $bookingTypeGroups = [
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 1,
                        'name' => 'car',
                        'display_name' => 'רכב',
                        'icon' => BookingTypeIconEnum::CAR->value,
                        'position' => BookingTypePositionEnum::CAR->value,
                    ],
                ],
                'booking_types' => [
                    0 => [
                        'id' => 1,
                        'name' => 'car_booking',
                        'display_name' => 'רכב שיתופי',
                        'icon' => BookingTypeIconEnum::CAR_BOOKING->value,
                        'position' => BookingTypePositionEnum::CAR_BOOKING->value,
                        'has_group' => true,
                    ],
                    1 => [
                        'id' => 2,
                        'name' => 'car_maintenance_booking',
                        'display_name' => 'תיאום שינוע רכב לטיפול',
                        'icon' => BookingTypeIconEnum::CAR_MAINTENANCE_BOOKING->value,
                        'position' => BookingTypePositionEnum::CAR_MAINTENANCE_BOOKING->value,
                    ],
                ],
            ],
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 2,
                        'name' => 'equipment',
                        'display_name' => 'ציוד',
                        'icon' => BookingTypeIconEnum::EQUIPMENT_BOOKING->value,
                        'position' => BookingTypePositionEnum::EQUIPMENT_BOOKING->value,
                    ],
                ],
                'booking_types' => [
                    1 => [
                        'id' => 3,
                        'name' => 'equipment_booking_male',
                        'display_name' => 'ציוד גברים',
                        'icon' => BookingTypeIconEnum::EQUIPMENT_BOOKING_MALE->value,
                        'position' => BookingTypePositionEnum::EQUIPMENT_BOOKING_MALE->value,
                        'has_group' => true,
                    ],
                    2 => [
                        'id' => 4,
                        'name' => 'equipment_booking_female',
                        'display_name' => 'ציוד נשים',
                        'icon' => BookingTypeIconEnum::EQUIPMENT_BOOKING_FEMALE->value,
                        'position' => BookingTypePositionEnum::EQUIPMENT_BOOKING_FEMALE->value,
                        'has_group' => true,
                    ],
                ],
            ],
            [
                'group_booking_types' => null,
                'booking_types' => [
                    0 => [
                        'id' => 5,
                        'name' => 'barber_booking',
                        'display_name' => 'מספרה',
                        'icon' => BookingTypeIconEnum::BARBER_BOOKING->value,
                        'position' => BookingTypePositionEnum::BARBER_BOOKING->value,
                    ],
                ],
            ],
            [
                'group_booking_types' => null,
                'booking_types' => [
                    0 => [
                        'id' => 8,
                        'name' => 'transportation_booking',
                        'display_name' => 'הובלות',
                        'icon' => BookingTypeIconEnum::TRANSPORTATION_BOOKING->value,
                        'position' => BookingTypePositionEnum::TRANSPORTATION_BOOKING->value,
                    ],
                ],
            ],
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 4,
                        'name' => 'food',
                        'display_name' => 'מזון',
                        'icon' => BookingTypeIconEnum::FOOD_BOOKING->value,
                        'position' => BookingTypePositionEnum::FOOD_BOOKING->value,
                    ],
                ],
                'booking_types' => [
                    1 => [
                        'id' => 10,
                        'name' => 'out_unit_food_booking',
                        'display_name' => 'מזון מחוץ ליחידה',
                        'icon' => BookingTypeIconEnum::OUT_UNIT_FOOD_BOOKING->value,
                        'position' => BookingTypePositionEnum::OUT_UNIT_FOOD_BOOKING->value,
                    ],
                    2 => [
                        'id' => 11,
                        'name' => 'in_unit_food_booking',
                        'display_name' => 'מזון בתוך היחידה',
                        'icon' => BookingTypeIconEnum::IN_UNIT_FOOD_BOOKING->value,
                        'position' => BookingTypePositionEnum::IN_UNIT_FOOD_BOOKING->value,
                    ],
                ],
            ],
            [
                'group_booking_types' => null,
                'booking_types' => [
                    0 => [
                        'id' => 13,
                        'name' => 'construction_booking',
                        'display_name' => 'בינוי',
                        'is_outsource' => false,
                        'url' => null,
                        'icon' => BookingTypeIconEnum::CONSTRUCTION_BOOKING->value,
                        'position' => BookingTypePositionEnum::CONSTRUCTION_BOOKING->value,
                    ],
                ],
            ],
        ];

        Model::unguarded(function () use ($bookingTypeGroups) {
            foreach ($bookingTypeGroups as $groupBookingData) {
                $groupBookingTypes = $groupBookingData['group_booking_types'];
                $bookingTypes = $groupBookingData['booking_types'];

                foreach ($groupBookingTypes ?? [null] as $groupBookingTypeData) {
                    $groupBookingType = $groupBookingTypeData
                        ? GroupBookingType::updateOrCreate(
                            ['id' => $groupBookingTypeData['id']],
                            array_diff_key($groupBookingTypeData, ['id' => true])
                        )
                        : null;

                    foreach ($bookingTypes as $bookingTypeData) {
                        $bookingType = BookingType::updateOrCreate(
                            ['id' => $bookingTypeData['id']],
                            array_diff_key($bookingTypeData, ['id' => true])
                        );

                        if ($groupBookingType) {
                            GroupBookingTypeHasBookingType::updateOrCreate([
                                'group_booking_id' => $groupBookingType->id,
                                'booking_type_id' => $bookingType->id,
                            ]);
                        }
                    }
                }
            }
        });
    }
}

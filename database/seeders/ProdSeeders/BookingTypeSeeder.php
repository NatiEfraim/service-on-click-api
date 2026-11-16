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
    /** Ids match the mobile app's BookingTypeSeeder (outsourced types stay unused); exported bookings carry them. */
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

            // ביטחון מידע – two sub-groups, both under topic='infosec'
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 5,
                        'name' => 'isec_clearance',
                        'display_name' => 'סיווג ביטחוני',
                        'icon' => BookingTypeIconEnum::INFOSEC_GROUP->value,
                        'position' => '30',
                        'topic' => 'infosec',
                    ],
                ],
                'booking_types' => [
                    0 => [
                        'id' => 14,
                        'name' => 'clearance_grant',
                        'display_name' => 'מתן סיווג ביטחוני',
                        'description' => 'בקשה לקבלת סיווג ביטחוני או העלאת רמת סיווג',
                        'icon' => BookingTypeIconEnum::CLEARANCE_GRANT_BOOKING->value,
                        'position' => BookingTypePositionEnum::CLEARANCE_GRANT_BOOKING->value,
                        'has_group' => true,
                    ],
                ],
            ],
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 6,
                        'name' => 'isec_protection',
                        'display_name' => 'אבטחת מידע',
                        'icon' => BookingTypeIconEnum::INFOSEC_GROUP->value,
                        'position' => '31',
                        'topic' => 'infosec',
                    ],
                ],
                'booking_types' => [
                    0 => [
                        'id' => 15,
                        'name' => 'removable_media',
                        'display_name' => 'אישור הכנסת מדיה נתיקה',
                        'description' => 'אישור להכנסת התקן אחסון נייד לרשת',
                        'icon' => BookingTypeIconEnum::REMOVABLE_MEDIA_BOOKING->value,
                        'position' => BookingTypePositionEnum::REMOVABLE_MEDIA_BOOKING->value,
                        'has_group' => true,
                    ],
                    1 => [
                        'id' => 16,
                        'name' => 'security_incident',
                        'display_name' => 'דיווח על אירוע ביטחון מידע',
                        'description' => 'דיווח על חשד לאירוע או חשיפת מידע',
                        'icon' => BookingTypeIconEnum::SECURITY_INCIDENT_BOOKING->value,
                        'position' => BookingTypePositionEnum::SECURITY_INCIDENT_BOOKING->value,
                        'has_group' => true,
                    ],
                ],
            ],

            // תקשוב – one sub-group under topic='ict'
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 7,
                        'name' => 'ict_permissions',
                        'display_name' => 'הרשאות',
                        'icon' => BookingTypeIconEnum::ICT_GROUP->value,
                        'position' => '32',
                        'topic' => 'ict',
                    ],
                ],
                'booking_types' => [
                    0 => [
                        'id' => 17,
                        'name' => 'folder_permission',
                        'display_name' => 'הרשאות לתיקייה',
                        'description' => 'הוספה או שינוי של הרשאות לתיקייה קיימת',
                        'icon' => BookingTypeIconEnum::FOLDER_PERMISSION_BOOKING->value,
                        'position' => BookingTypePositionEnum::FOLDER_PERMISSION_BOOKING->value,
                        'has_group' => true,
                    ],
                    1 => [
                        'id' => 18,
                        'name' => 'manta_permission',
                        'display_name' => 'הרשאות למנת"ה',
                        'description' => 'בקשת הרשאה למערכת מנת"ה',
                        'icon' => BookingTypeIconEnum::MANTA_PERMISSION_BOOKING->value,
                        'position' => BookingTypePositionEnum::MANTA_PERMISSION_BOOKING->value,
                        'has_group' => true,
                    ],
                    2 => [
                        'id' => 19,
                        'name' => 'network_folder',
                        'display_name' => 'יצירת תיקיית רשת',
                        'description' => 'פתיחת תיקייה משותפת חדשה ברשת',
                        'icon' => BookingTypeIconEnum::NETWORK_FOLDER_BOOKING->value,
                        'position' => BookingTypePositionEnum::NETWORK_FOLDER_BOOKING->value,
                        'has_group' => true,
                    ],
                ],
            ],

            // תקשוב – installations (beside types 17-19, which stay until the product owner decides)
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 8,
                        'name' => 'ict_installations',
                        'display_name' => 'התקנות',
                        'icon' => BookingTypeIconEnum::ICT_INSTALL_GROUP->value,
                        'position' => '33',
                        'topic' => 'ict',
                    ],
                ],
                'booking_types' => [
                    0 => [
                        'id' => 20,
                        'name' => 'software_install',
                        'display_name' => 'התקנת תוכנה',
                        'description' => 'התקנת תוכנה על מחשב ברשת',
                        'icon' => BookingTypeIconEnum::SOFTWARE_INSTALL_BOOKING->value,
                        'position' => BookingTypePositionEnum::SOFTWARE_INSTALL_BOOKING->value,
                        'has_group' => true,
                    ],
                    1 => [
                        'id' => 21,
                        'name' => 'printer_install',
                        'display_name' => 'התקנת מדפסת',
                        'description' => 'חיבור מדפסת קיימת או התקנת מדפסת חדשה',
                        'icon' => BookingTypeIconEnum::PRINTER_INSTALL_BOOKING->value,
                        'position' => BookingTypePositionEnum::PRINTER_INSTALL_BOOKING->value,
                        'has_group' => true,
                    ],
                ],
            ],

            // תקשוב – faults
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 9,
                        'name' => 'ict_faults',
                        'display_name' => 'תקלות',
                        'icon' => BookingTypeIconEnum::ICT_FAULT_GROUP->value,
                        'position' => '34',
                        'topic' => 'ict',
                    ],
                ],
                'booking_types' => [
                    0 => [
                        'id' => 22,
                        'name' => 'email_fault',
                        'display_name' => 'תקלה במייל',
                        'description' => 'דיווח על תקלה בתיבת המייל',
                        'icon' => BookingTypeIconEnum::EMAIL_FAULT_BOOKING->value,
                        'position' => BookingTypePositionEnum::EMAIL_FAULT_BOOKING->value,
                        'has_group' => true,
                    ],
                ],
            ],

            // תקשוב – user management
            [
                'group_booking_types' => [
                    0 => [
                        'id' => 10,
                        'name' => 'ict_users',
                        'display_name' => 'ניהול משתמשים',
                        'icon' => BookingTypeIconEnum::ICT_USERS_GROUP->value,
                        'position' => '35',
                        'topic' => 'ict',
                    ],
                ],
                'booking_types' => [
                    0 => [
                        'id' => 23,
                        'name' => 'user_rename',
                        'display_name' => 'שינוי שם משתמש',
                        'description' => 'שינוי שם התצוגה של משתמש קיים',
                        'icon' => BookingTypeIconEnum::USER_RENAME_BOOKING->value,
                        'position' => BookingTypePositionEnum::USER_RENAME_BOOKING->value,
                        'has_group' => true,
                    ],
                    1 => [
                        'id' => 24,
                        'name' => 'user_open',
                        'display_name' => 'פתיחת משתמש',
                        'description' => 'פתיחת משתמש חדש ברשת',
                        'icon' => BookingTypeIconEnum::USER_OPEN_BOOKING->value,
                        'position' => BookingTypePositionEnum::USER_OPEN_BOOKING->value,
                        'has_group' => true,
                    ],
                    2 => [
                        'id' => 25,
                        'name' => 'user_move',
                        'display_name' => 'העברת משתמש יחידה',
                        'description' => 'העברת משתמש קיים ליחידה אחרת',
                        'icon' => BookingTypeIconEnum::USER_MOVE_BOOKING->value,
                        'position' => BookingTypePositionEnum::USER_MOVE_BOOKING->value,
                        'has_group' => true,
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

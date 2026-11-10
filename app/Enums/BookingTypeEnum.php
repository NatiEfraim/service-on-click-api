<?php

namespace App\Enums;

use App\Models\BookingType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

enum BookingTypeEnum
{
    case EQUIPMENT_BOOKING;
    case EQUIPMENT_BOOKING_MALE;
    case EQUIPMENT_BOOKING_FEMALE;
    case CAR_BOOKING;
    case CAR_MAINTENANCE_BOOKING;
    case FOOD_BOOKING;
    case OUT_UNIT_FOOD_BOOKING;
    case IN_UNIT_FOOD_BOOKING;
    case BARBER_BOOKING;
    case LOUNGE_BOOKING;
    case TRANSPORTATION_BOOKING;
    case SUPPLIER_CERTIFICATE_BOOKING;
    case SHIFTS_BOOKING;
    case DIGITAL_TICKET_BOOKING;
    case DISCUSSION_ROOM_SCHEDULE_BOOKING;
    case WEAPON_TEST_BOOKING;
    case WEAPON_FORM_BOOKING;
    case CONSTRUCTION_BOOKING;
    case ENTRY_PERMISSION_BOOKING;
    case KITBAG_BOOKING;

    // Workspaces
    case WE_WORK_BOOKING;
    case LARGE_COMMAND_ROOM_BOOKING;
    case SMALL_COMMAND_ROOM_BOOKING;
    case LECTURE_ROOM_BOOKING;
    case TECH_HAMAMA_FIRST_BOOKING;
    case TECH_HAMAMA_SECOND_BOOKING;

    /** The booking type's DB id, memoized per request. */
    public function getBookingTypeId(): ?int
    {
        static $cache = [];

        $name = $this->getBookingTypeName();

        if (!$name) {
            return null;
        }

        if (!array_key_exists($name, $cache)) {
            $cache[$name] = BookingType::query()
                ->where('name', $name)
                ->value('id');
        }

        return $cache[$name];
    }

    public static function getBookingTypeRecord(int $bookingTypeId): ?BookingType
    {
        return BookingType::query()
            ->where('id', $bookingTypeId)
            ->first();
    }
    
    /** Route param to name, e.g. "car-booking" => "car_booking". */
    public static function normalizeBookingTypeName(string $bookingType): string
    {
        return Str::snake(str_replace(
            search: '-',
            replace: '_',
            subject: $bookingType
        ));
    }

    /** Name to route param, e.g. "car_booking" => "car-booking". */
    public static function formatBookingTypeRouteName(string $bookingTypeName): string
    {
        return str_replace(
            search: '_',
            replace: '-',
            subject: $bookingTypeName
        );
    }

    /** Booking type id from a route name, e.g. "car-booking" => 1. */
    public static function getBookingTypeIdFromRouteName(string $bookingType): ?int
    {
        $bookingTypeName = self::normalizeBookingTypeName($bookingType);

        $bookingTypeEnum = self::fromBookingTypeName($bookingTypeName);

        return $bookingTypeEnum?->getBookingTypeId();
    }

    public function getBookingTypeName(): ?string
    {
        return match ($this) {
            self::CAR_BOOKING => 'car_booking',
            self::EQUIPMENT_BOOKING => 'equipment_booking',
            self::CAR_MAINTENANCE_BOOKING => 'car_maintenance_booking',
            self::FOOD_BOOKING => 'food_booking',
            self::OUT_UNIT_FOOD_BOOKING => 'out_unit_food_booking',
            self::IN_UNIT_FOOD_BOOKING => 'in_unit_food_booking',

            self::BARBER_BOOKING => 'barber_booking',
            self::LOUNGE_BOOKING => 'lounge_booking',
            self::TRANSPORTATION_BOOKING => 'transportation_booking',
            self::SUPPLIER_CERTIFICATE_BOOKING => 'supplier_certificate_booking',
            self::SHIFTS_BOOKING => 'shifts_booking',
            self::DIGITAL_TICKET_BOOKING => 'digital_ticket_booking',
            self::DISCUSSION_ROOM_SCHEDULE_BOOKING => 'discussion_room_schedule_booking',
            self::WEAPON_TEST_BOOKING => 'weapon_test_booking',
            self::WEAPON_FORM_BOOKING => 'weapon_form_booking',
            self::CONSTRUCTION_BOOKING => 'construction_booking',
            self::ENTRY_PERMISSION_BOOKING => 'entry_permission_booking',
            self::KITBAG_BOOKING => 'kitbag_booking',
            self::EQUIPMENT_BOOKING_MALE => 'equipment_booking_male',
            self::EQUIPMENT_BOOKING_FEMALE => 'equipment_booking_female',



            self::WE_WORK_BOOKING => 'we_work_booking',
            self::LARGE_COMMAND_ROOM_BOOKING => 'large_command_room_booking',
            self::SMALL_COMMAND_ROOM_BOOKING => 'small_command_room_booking',
            self::LECTURE_ROOM_BOOKING => 'lecture_room_booking',
            self::TECH_HAMAMA_FIRST_BOOKING => 'tech_hamama_first_booking',
            self::TECH_HAMAMA_SECOND_BOOKING => 'tech_hamama_second_booking',
            default => null,
        };
    }

    /** Resolves the enum from the booking type name. */
    public static function fromBookingTypeName(string $name): ?self
    {
        return match ($name) {
            'car_booking' => self::CAR_BOOKING,
            'equipment_booking' => self::EQUIPMENT_BOOKING,
            'equipment' => self::EQUIPMENT_BOOKING,
            'equipment_booking_male' => self::EQUIPMENT_BOOKING_MALE,
            'equipment_booking_female' => self::EQUIPMENT_BOOKING_FEMALE,
            'car_maintenance_booking' => self::CAR_MAINTENANCE_BOOKING,

            'food_booking' => self::FOOD_BOOKING,
            'out_unit_food_booking' => self::OUT_UNIT_FOOD_BOOKING,
            'in_unit_food_booking' => self::IN_UNIT_FOOD_BOOKING,

            'barber_booking' => self::BARBER_BOOKING,
            'lounge_booking' => self::LOUNGE_BOOKING,
            'transportation_booking' => self::TRANSPORTATION_BOOKING,
            'supplier_certificate_booking' => self::SUPPLIER_CERTIFICATE_BOOKING,
            'shifts_booking' => self::SHIFTS_BOOKING,
            'digital_ticket_booking' => self::DIGITAL_TICKET_BOOKING,
            'discussion_room_schedule_booking' => self::DISCUSSION_ROOM_SCHEDULE_BOOKING,
         
            'weapon_test_booking' => self::WEAPON_TEST_BOOKING,
            'weapon_form_booking' => self::WEAPON_FORM_BOOKING,
            'construction_booking' => self::CONSTRUCTION_BOOKING,
            'entry_permission_booking' => self::ENTRY_PERMISSION_BOOKING,
            'kitbag_booking' => self::KITBAG_BOOKING,



            'we_work_booking' => self::WE_WORK_BOOKING,
            'large_command_room_booking' => self::LARGE_COMMAND_ROOM_BOOKING,
            'small_command_room_booking' => self::SMALL_COMMAND_ROOM_BOOKING,
            'lecture_room_booking' => self::LECTURE_ROOM_BOOKING,
            'tech_hamama_first_booking' => self::TECH_HAMAMA_FIRST_BOOKING,
            'tech_hamama_second_booking' => self::TECH_HAMAMA_SECOND_BOOKING,
            default => null,
        };
    }

    /** Display name, with per-unit overrides. */
    public function getCustomDisplayName(int $unitId): string|bool
    {
        try {
            $bookingTypeId = $this->getBookingTypeId();
            $bookingType = BookingType::where('id', $bookingTypeId)->first();

            return match ($this) {
                self::CAR_BOOKING => match ($unitId) {
                    UnitEnum::H->value => 'הזמנת רכב שיתופי',
                    default => $bookingType->display_name,
                },
                self::EQUIPMENT_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::EQUIPMENT_BOOKING_MALE => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::EQUIPMENT_BOOKING_FEMALE => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::CAR_MAINTENANCE_BOOKING => match ($unitId) {
                    UnitEnum::H->value => 'תיאום שינוע רכב לטיפול',
                    default => $bookingType->display_name,
                },
                self::FOOD_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::BARBER_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::LOUNGE_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::TRANSPORTATION_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::SUPPLIER_CERTIFICATE_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::SHIFTS_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::DIGITAL_TICKET_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::DISCUSSION_ROOM_SCHEDULE_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::WE_WORK_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::WEAPON_TEST_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::WEAPON_FORM_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::CONSTRUCTION_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::ENTRY_PERMISSION_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::LECTURE_ROOM_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::SMALL_COMMAND_ROOM_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::LARGE_COMMAND_ROOM_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::TECH_HAMAMA_FIRST_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                self::TECH_HAMAMA_SECOND_BOOKING => match ($unitId) {
                    default => $bookingType->display_name,
                },
                default => $bookingType->display_name,
            };
        } catch (\Exception $e) {
            Log::error('Error in BookingTypeEnum: getCustomDisplayName function: ' . $e->getMessage());
            return $bookingType->display_name;
        }
    }

    /** UI titles: nav title, form title and subtitle. */
    public function getSections(): array
    {
        return match ($this) {
            self::CAR_BOOKING => [
                'nav_title' => 'הזמנות רכב',
                'form_title' => 'הזמנת רכב',
                'form_subtitle' => 'מלאו את הפרטים בהתאם',
            ],
            self::FOOD_BOOKING => [
                'nav_title' => 'הזמנות מזון',
                'form_title' => 'הזמנת מזון',
                'form_subtitle' => 'מלאו את הפרטים בהתאם',
            ],
            self::EQUIPMENT_BOOKING => [
                'nav_title' => 'הזמנות ציוד',
                'form_title' => 'הזמנת ציוד',
                'form_subtitle' => 'מלאו את הפרטים בהתאם',
            ],
            default => [],
        };
    }

    public function getManagementNavTitle(): ?string
    {
        return match ($this) {
            self::CAR_BOOKING => 'ניהול הזמנות רכב',
            self::FOOD_BOOKING => 'ניהול הזמנות מזון',
            self::EQUIPMENT_BOOKING => 'ניהול הזמנות ציוד',
            self::CAR_MAINTENANCE_BOOKING => 'ניהול תיקוני רכב',
            self::BARBER_BOOKING => 'ניהול מספרה',
            self::TRANSPORTATION_BOOKING => 'ניהול הובלות',

            self::WE_WORK_BOOKING => 'ניהול WeWork',
            self::LARGE_COMMAND_ROOM_BOOKING => 'ניהול חד"ן רחט"ל גדול',
            self::SMALL_COMMAND_ROOM_BOOKING => 'ניהול חד"ן רחט"ל קטן',
            self::LECTURE_ROOM_BOOKING => 'ניהול חדר הרצאות',
            self::TECH_HAMAMA_FIRST_BOOKING => 'ניהול חממה טכנולוגית 1',
            self::TECH_HAMAMA_SECOND_BOOKING => 'ניהול חממה טכנולוגית 2',
            default => null,
        };
    }


    public function getManagementSettingsNavTitle(): ?string
    {
        return match ($this) {
            self::CAR_BOOKING => 'הגדרות הזמנות רכב',
            self::FOOD_BOOKING => 'הגדרות הזמנות מזון',
            self::EQUIPMENT_BOOKING => 'הגדרות הזמנות ציוד',
            self::CAR_MAINTENANCE_BOOKING => 'הגדרות תיקוני רכב',
            self::BARBER_BOOKING => 'הגדרות מספרה',
            self::TRANSPORTATION_BOOKING => 'הגדרות הובלות',

            self::WE_WORK_BOOKING=> 'ניהול - we-work',
            self::LARGE_COMMAND_ROOM_BOOKING => 'הגדרות חד"ן רחט"ל גדול',
            self::SMALL_COMMAND_ROOM_BOOKING => 'הגדרות חד"ן רחט"ל קטן',
            self::LECTURE_ROOM_BOOKING => 'הגדרות חדר הרצאות',
            self::TECH_HAMAMA_FIRST_BOOKING => 'הגדרות חממה טכנולוגית 1',
            self::TECH_HAMAMA_SECOND_BOOKING => 'הגדרות חממה טכנולוגית 2',
            default => null,
        };
    }

    /** Expected form field count (exact or max) for a booking type id. */
    public static function getExpectedFieldCountById(int $bookingTypeId): ?array
    {

        $mapping = [
            self::CAR_BOOKING->getBookingTypeId() => ['exact' => 6],
            self::FOOD_BOOKING->getBookingTypeId() => ['exact' => 9],
            self::EQUIPMENT_BOOKING->getBookingTypeId() => ['max' => 4], 
            self::EQUIPMENT_BOOKING_MALE->getBookingTypeId() => ['max' => 4], 
            self::EQUIPMENT_BOOKING_FEMALE->getBookingTypeId() => ['max' => 4], 
            self::CAR_MAINTENANCE_BOOKING->getBookingTypeId() => ['max' => 11],
            self::TRANSPORTATION_BOOKING->getBookingTypeId() => ['max' => 5],
            self::LOUNGE_BOOKING->getBookingTypeId() => ['max' => 5],
            self::BARBER_BOOKING->getBookingTypeId() => ['exact' => 2],
            self::WE_WORK_BOOKING->getBookingTypeId() => ['exact' => 4],
            self::LECTURE_ROOM_BOOKING->getBookingTypeId() => ['exact' => 4],
            self::SMALL_COMMAND_ROOM_BOOKING->getBookingTypeId() => ['exact' => 4],
            self::LARGE_COMMAND_ROOM_BOOKING->getBookingTypeId() => ['exact' => 4],
            self::TECH_HAMAMA_FIRST_BOOKING->getBookingTypeId() => ['exact' => 4],
            self::TECH_HAMAMA_SECOND_BOOKING->getBookingTypeId() => ['exact' => 4],
        ];

        return $mapping[$bookingTypeId] ?? null;
    }

    /** Whether this booking type has a restriction-dates (תאריכים חסומים) system reference. */
    public static function hasRestrictionDates(int $bookingTypeId): bool
    {
        return in_array($bookingTypeId, array_filter([
            self::CAR_BOOKING->getBookingTypeId(),
            self::BARBER_BOOKING->getBookingTypeId(),
            self::IN_UNIT_FOOD_BOOKING->getBookingTypeId(),
            self::OUT_UNIT_FOOD_BOOKING->getBookingTypeId(),
            self::TRANSPORTATION_BOOKING->getBookingTypeId(),
            self::CAR_MAINTENANCE_BOOKING->getBookingTypeId(),
        ]), true);
    }

    public static function getCancelStatusForRestrictionDates(int $bookingTypeId): StatusBookingEnum
    {
        return match ($bookingTypeId) {
            self::BARBER_BOOKING->getBookingTypeId() =>
                StatusBookingEnum::CANCELED_BY_CHANGING_TIME,
            default =>
                StatusBookingEnum::CANCELED_BY_APPROVER,
        };
    }
}

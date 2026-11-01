<?php

namespace App\Enums;

use App\Models\SystemReference;
use App\Models\SystemReferenceHasFormField;
use App\Models\BookingRestrictionDate;




enum SystemReferenceEnum
{
    // CAR BOOKING
    case CAR_TOTAL_CAR_STOCK;
    case CAR_SERVICE_HOURS;
    case CAR_CONTACT_PHONE;
    case CAR_RESTRICTIONS;
    case CAR_RESTRICTION_DATES;

    // IN_UNIT_FOOD_BOOKING
    case IN_UNIT_FOOD_CONTACT_PHONE;
    case IN_UNIT_RESTRICTION_DATES;
    case IN_UNIT_FOOD_RESTRICTIONS;
    case IN_UNIT_FOOD_SERVICE_HOURS;
    case IN_UNIT_FOOD_PEOPLE_AMOUNT;
    case IN_UNIT_FOOD_COUNT_BUSINESS_DAYS;
    case IN_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS;
    case IN_UNIT_FOOD_MAX_ADVANCE_DAYS;

    // OUT_UNIT_FOOD_BOOKING
    case OUT_UNIT_FOOD_CONTACT_PHONE;
    case OUT_UNIT_RESTRICTION_DATES;
    case OUT_UNIT_FOOD_RESTRICTIONS;
    case OUT_UNIT_FOOD_SERVICE_HOURS;
    case OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS;
    case OUT_UNIT_FOOD_MINIMUM_ORDER_AMOUNT;
    case OUT_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS;
    case OUT_UNIT_FOOD_MAX_ADVANCE_DAYS;


    // EQUIPMENT BOOKING MALE
    case EQUIPMENT_MALE_CONTACT_PHONE;
    case EQUIPMENT_MALE_QTY_LIMIT_CLOTHING;
    case EQUIPMENT_MALE_QTY_LIMIT_FOOTWEAR;
    case EQUIPMENT_MALE_QTY_LIMIT_RELATED_PRODUCTS;

    // EQUIPMENT BOOKING FEMALE
    case EQUIPMENT_FEMALE_CONTACT_PHONE;
    case EQUIPMENT_FEMALE_QTY_LIMIT_CLOTHING;
    case EQUIPMENT_FEMALE_QTY_LIMIT_FOOTWEAR;
    case EQUIPMENT_FEMALE_QTY_LIMIT_RELATED_PRODUCTS;

    // BARBER BOOKING
    case BARBER_DATE_ACTIVITI;
    case BARBER_CONTACT_PHONE;
    case BARBER_SERVICE_HOURS;
    case BARBER_BREAK_HOURS;
    case BARBER_HAIRCUT_TIME;
    case BARBER_CLEAN_TIME;
    case BARBER_ADVANCE_TIME;
    case BARBER_ADVANCE_MONTHS;
    case BARBER_RESTRICTION_DATES;


    // FOOD BOOKING
    case FOOD_CONTACT_PHONE;
    case FOOD_RESTRICTIONS;
    case FOOD_SERVICE_HOURS;
    // LECTURE ROOM BOOKING
    case LECTURE_ROOM_CONTACT_PHONE;
    case LECTURE_ROOM_SERVICE_HOURS;
    case LECTURE_ROOM_RESTRICTIONS;
    case LECTURE_ROOM_DURATION_TIME;

    // SMALL COMMAND ROOM BOOKING
    case SMALL_COMMNAD_ROOM_CONTACT_PHONE;
    case SMALL_COMMNAD_ROOM_SERVICE_HOURS;
    case SMALL_COMMNAD_ROOM_RESTRICTIONS;
    case SMALL_COMMNAD_ROOM_DURATION_TIME;

    // TECH HAMAMA FIRST BOOKING
    case TECH_HAMAMA_FIRST_CONTACT_PHONE;
    case TECH_HAMAMA_FIRST_SERVICE_HOURS;
    case TECH_HAMAMA_FIRST_RESTRICTIONS;
    case TECH_HAMAMA_FIRST_DURATION_TIME;

    // TECH HAMAMA SECOND BOOKING
    case TECH_HAMAMA_SECOND_CONTACT_PHONE;
    case TECH_HAMAMA_SECOND_SERVICE_HOURS;
    case TECH_HAMAMA_SECOND_RESTRICTIONS;
    case TECH_HAMAMA_SECOND_DURATION_TIME;


    // TRANSPORTATION BOOKING
    case TRANSPORTATION_CONTACT_PHONE;
    case TRANSPORTATION_SERVICE_HOURS;
    case TRANSPORTATION_MINIMUM_ADVANCE_DAYS;
    case TRANSPORTATION_RESTRICTION_DATES;

    // CAR MAINTENANCE BOOKING
    case CAR_MAINTENANCE_CONTACT_PHONE;
    case CAR_MAINTENANCE_DRIVERS_AMOUNT;
    case CAR_MAINTENANCE_RESTRICTION_DATES;

    // CONSTRUCTION BOOKING
    case CONSTRUCTION_CONTACT_PHONE;



    /** System reference ids linked to a form field id. */
    public static function getSystemReferenceIdsByFormFieldId(int $formFieldId): array
    {
        return SystemReferenceHasFormField::query()
            ->where('form_field_id', $formFieldId)
            ->pluck('system_reference_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();
    }

    /** System reference ids linked to a booking type id. */
    public static function getSystemReferenceIdsByBookingTypeId(int $bookingTypeId): array
    {
        return SystemReference::query()
            ->where('booking_type_id', $bookingTypeId)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();
    }

    /** Unique system_references.name of this case. */
    public function getSystemReferenceName(): string
    {
        return match ($this) {
            self::CAR_TOTAL_CAR_STOCK => 'total_car_stock_car_booking',
            self::CAR_SERVICE_HOURS => 'service_hours_car_booking',
            self::CAR_CONTACT_PHONE => 'contact_phone_car_booking',
            self::CAR_RESTRICTIONS => 'restrictions_car_booking',
            self::CAR_RESTRICTION_DATES => 'restriction_dates_car_booking',
            self::IN_UNIT_FOOD_CONTACT_PHONE => 'contact_phone_in_unit_food_booking',
            self::IN_UNIT_RESTRICTION_DATES => 'restriction_dates_in_unit_food_booking',
            self::IN_UNIT_FOOD_RESTRICTIONS => 'restrictions_in_unit_food_booking',
            self::IN_UNIT_FOOD_SERVICE_HOURS => 'service_hours_in_unit_food_booking',
            self::IN_UNIT_FOOD_PEOPLE_AMOUNT => 'people_amount_in_unit_food_booking',
            self::IN_UNIT_FOOD_COUNT_BUSINESS_DAYS => 'count_businsday_in_unit_food_booking',
            self::IN_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS => 'cancellation_advance_days_in_unit_food_booking',
            self::IN_UNIT_FOOD_MAX_ADVANCE_DAYS => 'max_advance_days_in_unit_food_booking',
            self::OUT_UNIT_FOOD_CONTACT_PHONE => 'contact_phone_out_unit_food_booking',
            self::OUT_UNIT_RESTRICTION_DATES => 'restriction_dates_out_unit_food_booking',
            self::OUT_UNIT_FOOD_RESTRICTIONS => 'restrictions_out_unit_food_booking',
            self::OUT_UNIT_FOOD_SERVICE_HOURS => 'service_hours_out_unit_food_booking',
            self::OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS => 'count_businsday_out_unit_food_booking',
            self::OUT_UNIT_FOOD_MINIMUM_ORDER_AMOUNT
            => 'minimum_order_amount_out_unit_food_booking',
            self::OUT_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS
            => 'cancellation_advance_days_out_unit_food_booking',
            self::OUT_UNIT_FOOD_MAX_ADVANCE_DAYS => 'max_advance_days_out_unit_food_booking',

            self::EQUIPMENT_MALE_CONTACT_PHONE => 'contact_phone_equipment_booking_male',
            self::EQUIPMENT_MALE_QTY_LIMIT_CLOTHING => 'qty_limit_clothing_equipment_booking_male',
            self::EQUIPMENT_MALE_QTY_LIMIT_FOOTWEAR => 'qty_limit_footwear_equipment_booking_male',
            self::EQUIPMENT_MALE_QTY_LIMIT_RELATED_PRODUCTS => 'qty_limit_related_equipment_booking_male',

            self::EQUIPMENT_FEMALE_CONTACT_PHONE => 'contact_phone_equipment_booking_female',
            self::EQUIPMENT_FEMALE_QTY_LIMIT_CLOTHING => 'qty_limit_clothing_equipment_booking_female',
            self::EQUIPMENT_FEMALE_QTY_LIMIT_FOOTWEAR => 'qty_limit_footwear_equipment_booking_female',
            self::EQUIPMENT_FEMALE_QTY_LIMIT_RELATED_PRODUCTS => 'qty_limit_related_product_equipment_booking_female',

            self::BARBER_DATE_ACTIVITI => 'restrictions_barber_booking',
            self::BARBER_CONTACT_PHONE => 'contact_phone_barber_booking',
            self::BARBER_SERVICE_HOURS => 'service_hours_barber_booking',
            self::BARBER_BREAK_HOURS => 'break_service_hours_barber_booking',
            self::BARBER_HAIRCUT_TIME => 'hair_cut_restrictions_barber_booking',
            self::BARBER_CLEAN_TIME => 'clean_hair_cut_time_restrictions_barber_booking',
            self::BARBER_ADVANCE_TIME => 'advance_time_restrictions_barber_booking',
            self::BARBER_ADVANCE_MONTHS => 'advance_month_restrictions_barber_booking',
            self::BARBER_RESTRICTION_DATES => 'restriction_dates_barber_booking',

            self::FOOD_CONTACT_PHONE => 'contact_phone_food_booking',
            self::FOOD_RESTRICTIONS => 'restrictions_food_booking',
            self::FOOD_SERVICE_HOURS => 'service_hours_food_booking',
            self::LECTURE_ROOM_CONTACT_PHONE => 'contact_phone_lecture_room_booking',
            self::LECTURE_ROOM_SERVICE_HOURS => 'service_hours_lecture_room_booking',
            self::LECTURE_ROOM_RESTRICTIONS => 'restrictions_lecture_room_booking',
            self::LECTURE_ROOM_DURATION_TIME => 'duration_time_lecture_room_booking',

            self::SMALL_COMMNAD_ROOM_CONTACT_PHONE => 'contact_phone_small_command_room_booking',
            self::SMALL_COMMNAD_ROOM_SERVICE_HOURS => 'service_hours_small_command_room_booking',
            self::SMALL_COMMNAD_ROOM_RESTRICTIONS => 'restrictions_small_command_room_booking',
            self::SMALL_COMMNAD_ROOM_DURATION_TIME => 'duration_time_small_command_room_booking',

            self::TECH_HAMAMA_FIRST_CONTACT_PHONE => 'contact_phone_tech_hamama_first_booking',
            self::TECH_HAMAMA_FIRST_SERVICE_HOURS => 'service_hours_tech_hamama_first_booking',
            self::TECH_HAMAMA_FIRST_RESTRICTIONS => 'restrictions_tech_hamama_first_booking',
            self::TECH_HAMAMA_FIRST_DURATION_TIME => 'duration_time_tech_hamama_first_booking',

            self::TECH_HAMAMA_SECOND_CONTACT_PHONE => 'contact_phone_tech_hamama_second_booking',
            self::TECH_HAMAMA_SECOND_SERVICE_HOURS => 'service_hours_tech_hamama_second_booking',
            self::TECH_HAMAMA_SECOND_RESTRICTIONS => 'restrictions_tech_hamama_second_booking',
            self::TECH_HAMAMA_SECOND_DURATION_TIME => 'duration_time_tech_hamama_second_booking',


            self::TRANSPORTATION_CONTACT_PHONE => 'contact_phone_transportation_booking',
            self::TRANSPORTATION_SERVICE_HOURS => 'service_hours_transportation_booking',
            self::TRANSPORTATION_MINIMUM_ADVANCE_DAYS => 'minimum_advance_days_transportation_booking',
            self::TRANSPORTATION_RESTRICTION_DATES => 'restriction_dates_transportation_booking',
            self::CAR_MAINTENANCE_CONTACT_PHONE => 'contact_phone_car_maintenance_booking',
            self::CAR_MAINTENANCE_DRIVERS_AMOUNT => 'drivers_amount_car_maintenance_booking',
            self::CAR_MAINTENANCE_RESTRICTION_DATES => 'restriction_dates_car_maintenance_booking',
            self::CONSTRUCTION_CONTACT_PHONE => 'contact_phone_construction_booking',
        };
    }

    /** Finds the case by its unique system_references.name. */
    public static function fromSystemReferenceName(?string $name): ?self
    {
        return match ($name) {
            'total_car_stock_car_booking' => self::CAR_TOTAL_CAR_STOCK,
            'service_hours_car_booking' => self::CAR_SERVICE_HOURS,
            'contact_phone_car_booking' => self::CAR_CONTACT_PHONE,
            'restrictions_car_booking' => self::CAR_RESTRICTIONS,
            'restriction_dates_car_booking' => self::CAR_RESTRICTION_DATES,
            'contact_phone_food_booking' => self::FOOD_CONTACT_PHONE,
            'restrictions_food_booking' => self::FOOD_RESTRICTIONS,
            'service_hours_food_booking' => self::FOOD_SERVICE_HOURS,

            'contact_phone_in_unit_food_booking' => self::IN_UNIT_FOOD_CONTACT_PHONE,
            'restriction_dates_in_unit_food_booking' => self::IN_UNIT_RESTRICTION_DATES,
            'restrictions_in_unit_food_booking' => self::IN_UNIT_FOOD_RESTRICTIONS,
            'service_hours_in_unit_food_booking' => self::IN_UNIT_FOOD_SERVICE_HOURS,
            'people_amount_in_unit_food_booking' => self::IN_UNIT_FOOD_PEOPLE_AMOUNT,
            'count_businsday_in_unit_food_booking' => self::IN_UNIT_FOOD_COUNT_BUSINESS_DAYS,
            'cancellation_advance_days_in_unit_food_booking' => self::IN_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS,
            'max_advance_days_in_unit_food_booking' => self::IN_UNIT_FOOD_MAX_ADVANCE_DAYS,

            'contact_phone_out_unit_food_booking' => self::OUT_UNIT_FOOD_CONTACT_PHONE,
            'restriction_dates_out_unit_food_booking'=> self::OUT_UNIT_RESTRICTION_DATES,
            'restrictions_out_unit_food_booking' => self::OUT_UNIT_FOOD_RESTRICTIONS,
            'service_hours_out_unit_food_booking' => self::OUT_UNIT_FOOD_SERVICE_HOURS,
            'count_businsday_out_unit_food_booking' => self::OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS,
            'minimum_order_amount_out_unit_food_booking'
            => self::OUT_UNIT_FOOD_MINIMUM_ORDER_AMOUNT,
            'cancellation_advance_days_out_unit_food_booking'
            => self::OUT_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS,
            'max_advance_days_out_unit_food_booking' => self::OUT_UNIT_FOOD_MAX_ADVANCE_DAYS,

            'contact_phone_equipment_booking_male' => self::EQUIPMENT_MALE_CONTACT_PHONE,
            'qty_limit_clothing_equipment_booking_male' => self::EQUIPMENT_MALE_QTY_LIMIT_CLOTHING,
            'qty_limit_footwear_equipment_booking_male' => self::EQUIPMENT_MALE_QTY_LIMIT_FOOTWEAR,
            'qty_limit_related_equipment_booking_male' => self::EQUIPMENT_MALE_QTY_LIMIT_RELATED_PRODUCTS,

            'contact_phone_equipment_booking_female' => self::EQUIPMENT_FEMALE_CONTACT_PHONE,
            'qty_limit_clothing_equipment_booking_female' => self::EQUIPMENT_FEMALE_QTY_LIMIT_CLOTHING,
            'qty_limit_footwear_equipment_booking_female' => self::EQUIPMENT_FEMALE_QTY_LIMIT_FOOTWEAR,
            'qty_limit_related_product_equipment_booking_female' => self::EQUIPMENT_FEMALE_QTY_LIMIT_RELATED_PRODUCTS,

            'restrictions_barber_booking' => self::BARBER_DATE_ACTIVITI,
            'contact_phone_barber_booking' => self::BARBER_CONTACT_PHONE,
            'service_hours_barber_booking' => self::BARBER_SERVICE_HOURS,
            'break_service_hours_barber_booking' => self::BARBER_BREAK_HOURS,
            'hair_cut_restrictions_barber_booking' => self::BARBER_HAIRCUT_TIME,
            'clean_hair_cut_time_restrictions_barber_booking' => self::BARBER_CLEAN_TIME,
            'advance_time_restrictions_barber_booking' => self::BARBER_ADVANCE_TIME,
            'advance_month_restrictions_barber_booking' => self::BARBER_ADVANCE_MONTHS,
            'restriction_dates_barber_booking' => self::BARBER_RESTRICTION_DATES,

            'contact_phone_lecture_room_booking' => self::LECTURE_ROOM_CONTACT_PHONE,
            'service_hours_lecture_room_booking' => self::LECTURE_ROOM_SERVICE_HOURS,
            'restrictions_lecture_room_booking' => self::LECTURE_ROOM_RESTRICTIONS,
            'duration_time_lecture_room_booking' => self::LECTURE_ROOM_DURATION_TIME,

            'contact_phone_small_command_room_booking' => self::SMALL_COMMNAD_ROOM_CONTACT_PHONE,
            'service_hours_small_command_room_booking' => self::SMALL_COMMNAD_ROOM_SERVICE_HOURS,
            'restrictions_small_command_room_booking' => self::SMALL_COMMNAD_ROOM_RESTRICTIONS,
            'duration_time_small_command_room_booking' => self::SMALL_COMMNAD_ROOM_DURATION_TIME,

            'contact_phone_tech_hamama_first_booking' => self::TECH_HAMAMA_FIRST_CONTACT_PHONE,
            'service_hours_tech_hamama_first_booking' => self::TECH_HAMAMA_FIRST_SERVICE_HOURS,
            'restrictions_tech_hamama_first_booking' => self::TECH_HAMAMA_FIRST_RESTRICTIONS,
            'duration_time_tech_hamama_first_booking' => self::TECH_HAMAMA_FIRST_DURATION_TIME,

            'contact_phone_tech_hamama_second_booking' => self::TECH_HAMAMA_SECOND_CONTACT_PHONE,
            'service_hours_tech_hamama_second_booking' => self::TECH_HAMAMA_SECOND_SERVICE_HOURS,
            'restrictions_tech_hamama_second_booking' => self::TECH_HAMAMA_SECOND_RESTRICTIONS,
            'duration_time_tech_hamama_second_booking' => self::TECH_HAMAMA_SECOND_DURATION_TIME,

            'contact_phone_transportation_booking' => self::TRANSPORTATION_CONTACT_PHONE,
            'service_hours_transportation_booking' => self::TRANSPORTATION_SERVICE_HOURS,
            'minimum_advance_days_transportation_booking' => self::TRANSPORTATION_MINIMUM_ADVANCE_DAYS,
            'restriction_dates_transportation_booking' => self::TRANSPORTATION_RESTRICTION_DATES,
            'contact_phone_car_maintenance_booking' => self::CAR_MAINTENANCE_CONTACT_PHONE,
            'drivers_amount_car_maintenance_booking' => self::CAR_MAINTENANCE_DRIVERS_AMOUNT,
            'restriction_dates_car_maintenance_booking' => self::CAR_MAINTENANCE_RESTRICTION_DATES,
            'contact_phone_construction_booking' => self::CONSTRUCTION_CONTACT_PHONE,

            default => null,
        };
    }
    
    /** This case's DB id, looked up by name and cached. */
    public function getSystemReferenceId(): ?int
    {
        static $cache = [];

        $name = $this->getSystemReferenceName();

        if (!array_key_exists($name, $cache)) {
            $cache[$name] = SystemReference::query()
                ->where('name', $name)
                ->value('id');
        }

        return $cache[$name];
    }


    public static function getBookingRestrictionDates(int $bookingTypeId): array
    {
        return BookingRestrictionDate::query()
            ->where('booking_type_id', $bookingTypeId)
            ->orderBy('date')
            ->pluck('date')
            ->map(function ($date) {
                return $date instanceof \Carbon\Carbon
                    ? $date->format('Y-m-d')
                    : \Carbon\Carbon::parse($date)->format('Y-m-d');
            })
            ->values()
            ->toArray();
    }

    /** Decoded system reference value of a booking type. */
    public static function getSystemReferenceValue(
        int $bookingTypeId,
        ?int $systemReferenceId,
    ): array {
        if (!$systemReferenceId) {
            return [];
        }

        $value = SystemReference::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('id', $systemReferenceId)
            ->value('value');

        if (!$value) {
            return [];
        }

        return json_decode($value, true) ?? [];
    }

    public static function isRestrictionDatesReference(
        int $bookingTypeId,
        int $systemReferenceId,
    ): bool {
        return match ($bookingTypeId) {
            BookingTypeEnum::CAR_BOOKING->getBookingTypeId() =>
            $systemReferenceId === self::CAR_RESTRICTION_DATES->getSystemReferenceId(),
            BookingTypeEnum::BARBER_BOOKING->getBookingTypeId() =>
            $systemReferenceId === self::BARBER_RESTRICTION_DATES->getSystemReferenceId(),
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId() =>
            $systemReferenceId === self::IN_UNIT_RESTRICTION_DATES->getSystemReferenceId(),
            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId() =>
            $systemReferenceId === self::OUT_UNIT_RESTRICTION_DATES->getSystemReferenceId(),
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId() =>
            $systemReferenceId === self::TRANSPORTATION_RESTRICTION_DATES->getSystemReferenceId(),
            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId() =>
            $systemReferenceId === self::CAR_MAINTENANCE_RESTRICTION_DATES->getSystemReferenceId(),
            default => false,
        };
    }
    
    public function label(): string
    {
        return match ($this) {
            self::CAR_TOTAL_CAR_STOCK => 'סה״כ רכבים להזמנה',
            self::CAR_SERVICE_HOURS => 'שעות פעילות הזמנת רכב',
            self::CAR_CONTACT_PHONE => 'טלפון ליצירת קשר להזמנת רכב',
            self::CAR_RESTRICTIONS => 'הגבלת זמני הזמנת רכב',

            self::CAR_MAINTENANCE_CONTACT_PHONE =>
            'טלפון ליצירת קשר להזמנת טיפולי רכב',

            self::EQUIPMENT_MALE_CONTACT_PHONE,self::EQUIPMENT_FEMALE_CONTACT_PHONE =>
            'טלפון ליצירת קשר להזמנת ציוד',

            self::BARBER_CONTACT_PHONE =>
            'טלפון ליצירת קשר להזמנת מספרה',

            self::BARBER_BREAK_HOURS =>
            'שעות פעילות בוקר — הזמנת מספרה',

    
            self::BARBER_HAIRCUT_TIME =>
            'זמן פעילות תספורת — הזמנת מספרה',

        

            self::FOOD_CONTACT_PHONE =>
            'טלפון ליצירת קשר — הזמנת מזון',

            self::FOOD_RESTRICTIONS =>
            'הגבלת זמני הזמנת מזון',

            self::FOOD_SERVICE_HOURS =>
            'שעות פעילות — הזמנת מזון',

        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::CAR_TOTAL_CAR_STOCK => 'סה״כ רכבים להזמנה',
            self::CAR_SERVICE_HOURS => 'שעות פעילות הזמנת רכב',
            self::CAR_CONTACT_PHONE => 'טלפון ליצירת קשר להזמנת רכב',
            self::CAR_RESTRICTIONS => 'הגבלת זמני הזמנת רכב',

            self::FOOD_CONTACT_PHONE => 'טלפון ליצירת קשר — הזמנת מזון',
            self::FOOD_RESTRICTIONS => 'הגבלת זמני הזמנת מזון',
            self::FOOD_SERVICE_HOURS => 'שעות פעילות — הזמנת מזון',

            self::EQUIPMENT_MALE_CONTACT_PHONE,self::EQUIPMENT_FEMALE_CONTACT_PHONE => 'טלפון ליצירת קשר להזמנת ציוד',
            self::TRANSPORTATION_CONTACT_PHONE => 'טלפון ליצירת קשר להזמנת הובלות',
            self::TRANSPORTATION_SERVICE_HOURS => 'שעות פעילות הזמנת הובלות',
            self::CAR_MAINTENANCE_CONTACT_PHONE => 'טלפון ליצירת קשר להזמנת טיפולי רכב',

            self::BARBER_DATE_ACTIVITI => 'תאריכים לעדכון שעות פעילות',
            self::BARBER_CONTACT_PHONE => 'טלפון ליצירת קשר להזמנת מספרה',
            self::BARBER_SERVICE_HOURS => 'שעות פעילות מספרה',
            self::BARBER_BREAK_HOURS => 'הפסקת צהריים — הזמנת מספרה',
            self::BARBER_HAIRCUT_TIME => 'משך זמן תספורת',
            self::BARBER_ADVANCE_TIME => 'זמן מינימלי להזמנה מראש',
            self::BARBER_ADVANCE_MONTHS => 'מספר חודשים להזמנה מראש',
        };
    }

    /** Hebrew label for a system reference value key. */
    private function getLabelForKey(string $key): string
    {
        return match ($key) {
            'stock_cars' => 'סה״כ כמות רכבים',
            'start_time' => 'שעת התחלה',
            'end_time' => 'שעת סיום',
            'contact_phone' => 'מספר טלפון',
            'bookings_limit_time' => 'מספר שעות',
            'limit_images' => 'מספר תמונות',
            'haircut_time' => 'זמן תספורת',
            'advance_month' => 'מספר חודשים',
            default => $key,
        };
    }
}

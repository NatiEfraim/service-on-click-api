<?php

namespace App\Enums;

use App\Models\FormField;
use App\Models\OptionField;

enum FormFieldEnum
{
    // CAR_BOOKING
    case CAR_BOOKING_START_DATE;
    case CAR_BOOKING_START_TIME;
    case CAR_BOOKING_END_DATE;
    case CAR_BOOKING_END_TIME;
    case CAR_BOOKING_DRIVE_PURPOSE;
    case CAR_BOOKING_NUMBER_OF_PASSENGERS;

    // IN_UNIT_FOOD_BOOKING
    case IN_UNIT_EVENT_NAME;
    case IN_UNIT_REFRESHMENTS;
    case IN_UNIT_AMOUNT_PEOPLE;
    case IN_UNIT_FOOD_NOTES;
    case IN_UNIT_SUPPLY_DATE;
    case IN_UNIT_SUPPLY_TIME;
    case IN_UNIT_CONTACT_NAME;
    case IN_UNIT_CONTACT_PHONE;
    case IN_UNIT_ADD_CONTACT_NAME;
    case IN_UNIT_ADD_CONTACT_PHONE;

    // OUT_UNIT_FOOD_BOOKING
    case OUT_UNIT_EVENT_NAME;
 
    case OUT_UNIT_TYPE_FOOD;
    case OUT_UNIT_SANDWICHES;
    case OUT_UNIT_TRAYS;
    case OUT_UNIT_FOOD_CLASSIFICATION;
    case OUT_UNIT_DAIRY;
    case OUT_UNIT_MEAT;
    case OUT_UNIT_PARVE;
    case OUT_UNIT_AMOUNT_PEOPLE;

    case OUT_UNIT_FOOD_NOTES;

    case OUT_UNIT_SUPPLY_DATE;
    case OUT_UNIT_SUPPLY_TIME;
    case OUT_UNIT_SUPPLY_ADDRESS;
    case OUT_UNIT_CONTACT_NAME;
    case OUT_UNIT_CONTACT_PHONE;
    case OUT_UNIT_ADD_CONTACT_NAME;
    case OUT_UNIT_ADD_CONTACT_PHONE;

    // EQUIPMENT_BOOKING_MALE / FEMALE - root wrapper fields
    case EQUIPMENT_MALE;
    case EQUIPMENT_FEMALE;

    // EQUIPMENT_BOOKING_MALE - parent fields
    case CLOTHING_MALE;
    case FOOTWEAR_MALE;
    case RELATED_PRODUCTS_MALE;
    case CERTIFICATED_SUPPLIER_MALE;

    // EQUIPMENT_BOOKING_MALE
    case CLOTHING_MALE_SHIRT;
    case CLOTHING_MALE_PANTS;
    case FOOTWEAR_MALE_INFANTRY;
    case FOOTWEAR_MALE_VEGAN;
    case CLOTHING_MALE_COTTON_UNIFORM;

    // EQUIPMENT_BOOKING_MALE - request reason root (sibling of EQUIPMENT_MALE)
    case REQUEST_REASON_MALE;

    // EQUIPMENT_BOOKING_MALE - request reason leaf fields
    case REQUEST_REASON_WEAR_AND_TEAR_MALE;
    case REQUEST_REASON_WINTER_ORDER_MALE;
    case REQUEST_REASON_SIZE_CHANGE_MALE;
    case REQUEST_REASON_SOLDIER_ASSIGNMENT_MALE;
    case REQUEST_REASON_EXEMPTION_MALE;

    // EQUIPMENT_BOOKING_FEMALE - parent fields
    case CLOTHING_FEMALE;
    case FOOTWEAR_FEMALE;
    case RELATED_PRODUCTS_FEMALE;
    case CERTIFICATED_SUPPLIER_FEMALE;

    // EQUIPMENT_BOOKING_FEMALE
    case CLOTHING_FEMALE_SHIRT;
    case CLOTHING_FEMALE_PANTS;
    case CLOTHING_FEMALE_SKIRT;
    case FOOTWEAR_FEMALE_SHOES;
    case FOOTWEAR_FEMALE_VEGAN;
    case FOOTWEAR_FEMALE_INFANTRY;
    case CLOTHING_FEMALE_COTTON_UNIFORM;

    // EQUIPMENT_BOOKING_FEMALE - request reason root (sibling of EQUIPMENT_FEMALE)
    case REQUEST_REASON_FEMALE;

    // EQUIPMENT_BOOKING_FEMALE - request reason leaf fields
    case REQUEST_REASON_WEAR_AND_TEAR_FEMALE;
    case REQUEST_REASON_WINTER_ORDER_FEMALE;
    case REQUEST_REASON_SIZE_CHANGE_FEMALE;
    case REQUEST_REASON_SOLDIER_ASSIGNMENT_FEMALE;
    case REQUEST_REASON_EXEMPTION_FEMALE;

    // EQUIPMENT_BOOKING_MALE - child quantity fields
    case QUANTITY_CLOTHING_MALE;
    case QUANTITY_FOOTWEAR_MALE;
    case QUANTITY_RELATED_PRODUCTS_MALE;

    // EQUIPMENT_BOOKING_MALE - exemption attachment
    case REQUEST_REASON_EXEMPTION_MALE_ATTACHED_FILE;

    // EQUIPMENT_BOOKING_FEMALE - child quantity fields
    case QUANTITY_CLOTHING_FEMALE;
    case QUANTITY_FOOTWEAR_FEMALE;
    case QUANTITY_RELATED_PRODUCTS_FEMALE;

    // EQUIPMENT_BOOKING_FEMALE - exemption attachment
    case REQUEST_REASON_EXEMPTION_FEMALE_ATTACHED_FILE;

    // CAR_MAINTENANCE_BOOKING
    case CAR_MAINTANENCE_COMPANY_NAME;
    case CAR_MAINTANENCE_MODEL;
    case CAR_MAINTANENCE_VEHICLE_NUMBER;
    case CAR_MAINTANENCE_CAR_CODE;
    case CAR_MAINTANENCE_FUEL_CODE;
    case CAR_MAINTANENCE_CURRENT_KILOMETER;
    case CAR_MAINTANENCE_CORE_PROBLEM;
    case CAR_MAINTANENCE_GARAGE_NAME;
    case CAR_MAINTANENCE_GARAGE_ADDRESS;
    case CAR_MAINTANENCE_DATE_SET;
    case CAR_MAINTANENCE_COMMENT;

    // BARBER_BOOKING
    case BARBER_DATE;
    case BARBER_TIME;

    // FOOD_BOOKING
    case EVENT_NAME;
    case EVENT_TYPE;
    case LOCATION;
    case SUPPLY_DATE;
    case SUPPLY_TIME;
    case GUESTS_NUM;
    case ALLERGY_BTN;
    case CONTACT_NAME;
    case CONTACT_PHONE;
    // LECTURE_ROOM_BOOKING
    case LECTURE_ROOM_DATE;
    case LECTURE_ROOM_TIME;
    case LECTURE_ROOM_USER_AMOUNT;
    case LECTURE_ROOM_EVENT_PURPOSE;

    // SMALL_COMMAND_ROOM_BOOKING
    case SMALL_COMMAND_ROOM_DATE;
    case SMALL_COMMAND_ROOM_TIME;
    case SMALL_COMMAND_ROOM_USER_AMOUNT;
    case SMALL_COMMAND_ROOM_EVENT_PURPOSE;

    // TECH_HAMAMA_FIRST_BOOKING
    case TECH_HAMAMA_FIRST_DATE;
    case TECH_HAMAMA_FIRST_TIME;
    case TECH_HAMAMA_FIRST_USER_AMOUNT;
    case TECH_HAMAMA_FIRST_EVENT_PURPOSE;

    // TECH_HAMAMA_SECOND_BOOKING
    case TECH_HAMAMA_SECOND_DATE;
    case TECH_HAMAMA_SECOND_TIME;
    case TECH_HAMAMA_SECOND_USER_AMOUNT;
    case TECH_HAMAMA_SECOND_EVENT_PURPOSE;

    // CONSTRUCTION_BOOKING
    case CONSTRUCTION_BUILDING_NUMBER;
    case CONSTRUCTION_NOTES;
    case CONSTRUCTION_IMAGES;

    // CONSTRUCTION_BOOKING
    case CONSTRUCTION_FAULT_TYPE;
    case CONSTRUCTION_ELECTRICITY;
    case CONSTRUCTION_STRUCTURE;
    case CONSTRUCTION_AC;
    case CONSTRUCTION_PLUMBING;

    // TRANSPORTATION_BOOKING
    case TRANSPORTATION_ORDER_TYPE;
    case TRANSPORTATION_CARGO;
    case TRANSPORTATION_VEHICLE_TYPE;
    case TRANSPORTATION_ASSEMBLY_POINT;
    case TRANSPORTATION_START_DATE;
    case TRANSPORTATION_START_TIME;
    case TRANSPORTATION_END_DATE;
    case TRANSPORTATION_END_TIME;
    case TRANSPORTATION_CONTACT_NAME;
    case TRANSPORTATION_CONTACT_PHONE;
    case TRANSPORTATION_ADD_CONTACT_NAME;
    case TRANSPORTATION_ADD_CONTACT_PHONE;
    case TRANSPORTATION_NOTES;

    /** Finds the case matching a form field id. */
    public static function fromFormFieldId(int $formFieldId): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->getFormFieldId() === $formFieldId) {
                return $case;
            }
        }

        return null;
    }

    /** Settings display name: the enum first, then the DB; memoized. */
    public static function getSettingsDisplayNameByFormFieldId(int $formFieldId): ?string
    {
        static $cache = [];

        if (array_key_exists($formFieldId, $cache)) {
            return $cache[$formFieldId];
        }

        $enum = self::fromFormFieldId($formFieldId);

        if ($enum) {
            return $cache[$formFieldId] = $enum->getSettingsDisplayName();
        }

        return $cache[$formFieldId] = FormField::query()
            ->where('id', $formFieldId)
            ->value('field_display_name');
    }

    public function getFormFieldName(): ?string
    {
        return match ($this) {
            self::CAR_BOOKING_START_DATE => 'car_booking_start_date',
            self::CAR_BOOKING_START_TIME => 'car_booking_start_time',
            self::CAR_BOOKING_END_DATE => 'car_booking_end_date',
            self::CAR_BOOKING_END_TIME => 'car_booking_end_time',
            self::CAR_BOOKING_DRIVE_PURPOSE => 'car_booking_drive_purpose',
        
            self::EVENT_NAME => 'event_name',
            self::EVENT_TYPE => 'event_type',
            self::LOCATION => 'location',
            self::SUPPLY_DATE => 'supply_date',
            self::SUPPLY_TIME => 'supply_time',
            self::GUESTS_NUM => 'guests_num',
            self::ALLERGY_BTN => 'allergy_btn',
            self::CONTACT_NAME => 'contact_name',
            self::CONTACT_PHONE => 'contact_phone',
            self::IN_UNIT_EVENT_NAME => 'event_name_in_unit_food_booking',
            self::IN_UNIT_REFRESHMENTS => 'refreshments_in_unit_food_booking',
            self::IN_UNIT_AMOUNT_PEOPLE => 'amount_people_in_unit_food_booking',
            self::IN_UNIT_FOOD_NOTES => 'food_notes_in_unit_food_booking',
            self::IN_UNIT_SUPPLY_DATE => 'supply_date_in_unit_food_booking',
            self::IN_UNIT_SUPPLY_TIME => 'supply_time_in_unit_food_booking',
            self::IN_UNIT_CONTACT_NAME => 'contact_name_in_unit_food_booking',
            self::IN_UNIT_CONTACT_PHONE => 'contact_phone_in_unit_food_booking',
            self::IN_UNIT_ADD_CONTACT_NAME => 'add_contact_name_in_unit_food_booking',
            self::IN_UNIT_ADD_CONTACT_PHONE => 'add_contact_phone_in_unit_food_booking',

            self::OUT_UNIT_EVENT_NAME => 'event_name_out_unit_food_booking',
            self::OUT_UNIT_TYPE_FOOD => 'type_food_out_unit_food_booking',
            self::OUT_UNIT_SANDWICHES => 'sandwiches_out_unit_food_booking',
            self::OUT_UNIT_TRAYS => 'trays_out_unit_food_booking',
            self::OUT_UNIT_FOOD_CLASSIFICATION => 'food_classification_out_unit_food_booking',
            self::OUT_UNIT_DAIRY => 'dairy_out_unit_food_booking',
            self::OUT_UNIT_MEAT => 'meat_out_unit_food_booking',
            self::OUT_UNIT_PARVE => 'parve_out_unit_food_booking',
            self::OUT_UNIT_AMOUNT_PEOPLE => 'amount_people_out_unit_food_booking',
            self::OUT_UNIT_FOOD_NOTES => 'food_notes_out_unit_food_booking',
            self::OUT_UNIT_SUPPLY_DATE => 'supply_date_out_unit_food_booking',
            self::OUT_UNIT_SUPPLY_TIME => 'supply_time_out_unit_food_booking',
            self::OUT_UNIT_SUPPLY_ADDRESS => 'supply_address_out_unit_food_booking',
            self::OUT_UNIT_CONTACT_NAME => 'contact_name_out_unit_food_booking',
            self::OUT_UNIT_CONTACT_PHONE => 'contact_phone_out_unit_food_booking',
            self::OUT_UNIT_ADD_CONTACT_NAME => 'add_contact_name_out_unit_food_booking',
            self::OUT_UNIT_ADD_CONTACT_PHONE => 'add_contact_phone_out_unit_food_booking',

            self::EQUIPMENT_MALE => 'equipment_male',
            self::EQUIPMENT_FEMALE => 'equipment_female',
            self::CLOTHING_MALE => 'clothing_male',
            self::FOOTWEAR_MALE => 'footwear_male',
            self::RELATED_PRODUCTS_MALE => 'related_products_male',
            self::CERTIFICATED_SUPPLIER_MALE => 'certificated_supplier_male',

            self::CLOTHING_MALE_SHIRT => 'clothing_male_shirt',
            self::CLOTHING_MALE_PANTS => 'clothing_male_pants',
            self::FOOTWEAR_MALE_INFANTRY => 'footwear_male_infantry',
            self::FOOTWEAR_MALE_VEGAN => 'footwear_male_vegan',
            self::CLOTHING_MALE_COTTON_UNIFORM => 'clothing_male_cotton_uniform',

            self::REQUEST_REASON_MALE => 'request_reason_male',
            self::REQUEST_REASON_WEAR_AND_TEAR_MALE => 'request_reason_wear_and_tear_male',
            self::REQUEST_REASON_WINTER_ORDER_MALE => 'request_reason_winter_order_male',
            self::REQUEST_REASON_SIZE_CHANGE_MALE => 'request_reason_size_change_male',
            self::REQUEST_REASON_SOLDIER_ASSIGNMENT_MALE => 'request_reason_soldier_assignment_male',
            self::REQUEST_REASON_EXEMPTION_MALE => 'request_reason_exemption_male',

            self::CLOTHING_FEMALE => 'clothing_female',
            self::FOOTWEAR_FEMALE => 'footwear_female',
            self::RELATED_PRODUCTS_FEMALE => 'related_products_female',
            self::CERTIFICATED_SUPPLIER_FEMALE => 'certificated_supplier_female',

            self::CLOTHING_FEMALE_SHIRT => 'clothing_female_shirt',
            self::CLOTHING_FEMALE_PANTS => 'clothing_female_pants',
            self::CLOTHING_FEMALE_SKIRT => 'clothing_female_skirt',
            self::FOOTWEAR_FEMALE_SHOES => 'footwear_female_shoes',
            self::FOOTWEAR_FEMALE_VEGAN => 'footwear_female_vegan',
            self::FOOTWEAR_FEMALE_INFANTRY => 'footwear_female_infantry',
            self::CLOTHING_FEMALE_COTTON_UNIFORM => 'clothing_female_cotton_uniform',

            self::REQUEST_REASON_FEMALE => 'request_reason_female',
            self::REQUEST_REASON_WEAR_AND_TEAR_FEMALE => 'request_reason_wear_and_tear_female',
            self::REQUEST_REASON_WINTER_ORDER_FEMALE => 'request_reason_winter_order_female',
            self::REQUEST_REASON_SIZE_CHANGE_FEMALE => 'request_reason_size_change_female',
            self::REQUEST_REASON_SOLDIER_ASSIGNMENT_FEMALE => 'request_reason_soldier_assignment_female',
            self::REQUEST_REASON_EXEMPTION_FEMALE => 'request_reason_exemption_female',

            self::QUANTITY_CLOTHING_MALE => 'quantity_clothing_male',
            self::QUANTITY_FOOTWEAR_MALE => 'quantity_footwear_male',
            self::QUANTITY_RELATED_PRODUCTS_MALE => 'quantity_related_products_male',

            self::REQUEST_REASON_EXEMPTION_MALE_ATTACHED_FILE => 'request_reason_exemption_male_attached_file',

            self::QUANTITY_CLOTHING_FEMALE => 'quantity_clothing_female',
            self::QUANTITY_FOOTWEAR_FEMALE => 'quantity_footwear_female',
            self::QUANTITY_RELATED_PRODUCTS_FEMALE => 'quantity_related_products_female',

            self::REQUEST_REASON_EXEMPTION_FEMALE_ATTACHED_FILE => 'request_reason_exemption_female_attached_file',

            self::CAR_MAINTANENCE_COMPANY_NAME => 'company_name_car_maintanence',
            self::CAR_MAINTANENCE_MODEL => 'model_car_maintanence',
            self::CAR_MAINTANENCE_VEHICLE_NUMBER => 'vehicle_number_car_maintanence',
            self::CAR_MAINTANENCE_CAR_CODE => 'car_code_car_maintanence',
            self::CAR_MAINTANENCE_FUEL_CODE => 'fuel_code_car_maintanence',
            self::CAR_MAINTANENCE_CURRENT_KILOMETER => 'current_kilometer_car_maintanence',
            self::CAR_MAINTANENCE_CORE_PROBLEM => 'core_problem_car_maintanence',
            self::CAR_MAINTANENCE_GARAGE_NAME => 'garage_name_car_maintanence',
            self::CAR_MAINTANENCE_GARAGE_ADDRESS => 'garage_address_car_maintanence',
            self::CAR_MAINTANENCE_DATE_SET => 'date_set_car_maintanence',
            self::CAR_MAINTANENCE_COMMENT => 'comment_car_maintanence',

            self::BARBER_DATE => 'barber_date',
            self::BARBER_TIME => 'barber_time',

            self::LECTURE_ROOM_DATE => 'lecture_room_date',
            self::LECTURE_ROOM_TIME => 'lecture_room_time',
            self::LECTURE_ROOM_USER_AMOUNT => 'lecture_room_user_amount',
            self::LECTURE_ROOM_EVENT_PURPOSE => 'lecture_room_event_purpose',

            self::SMALL_COMMAND_ROOM_DATE => 'small_command_room_date',
            self::SMALL_COMMAND_ROOM_TIME => 'small_command_room_time',
            self::SMALL_COMMAND_ROOM_USER_AMOUNT => 'small_command_room_user_amount',
            self::SMALL_COMMAND_ROOM_EVENT_PURPOSE => 'small_command_room_event_purpose',

            self::TECH_HAMAMA_FIRST_DATE => 'tech_hamama_first_date',
            self::TECH_HAMAMA_FIRST_TIME => 'tech_hamama_first_time',
            self::TECH_HAMAMA_FIRST_USER_AMOUNT => 'tech_hamama_first_user_amount',
            self::TECH_HAMAMA_FIRST_EVENT_PURPOSE => 'tech_hamama_first_event_purpose',

            self::TECH_HAMAMA_SECOND_DATE => 'tech_hamama_second_date',
            self::TECH_HAMAMA_SECOND_TIME => 'tech_hamama_second_time',
            self::TECH_HAMAMA_SECOND_USER_AMOUNT => 'tech_hamama_second_user_amount',
            self::TECH_HAMAMA_SECOND_EVENT_PURPOSE => 'tech_hamama_second_event_purpose',

            self::CONSTRUCTION_BUILDING_NUMBER => 'building_number_construction_booking',
            self::CONSTRUCTION_NOTES => 'notes_construction_booking',
            self::CONSTRUCTION_IMAGES => 'images_construction_booking',
            self::CONSTRUCTION_FAULT_TYPE => 'fault_type_construction_booking',
            self::CONSTRUCTION_ELECTRICITY => 'electricity_construction_booking',
            self::CONSTRUCTION_STRUCTURE => 'structure_construction_booking',
            self::CONSTRUCTION_AC => 'ac_construction_booking',
            self::CONSTRUCTION_PLUMBING => 'plumbing_construction_booking',

            self::TRANSPORTATION_ORDER_TYPE => 'order_type_transportation_booking',
            self::TRANSPORTATION_CARGO => 'cargo_transportation_booking',
            self::TRANSPORTATION_VEHICLE_TYPE => 'vehicle_type_transportation_booking',
            self::TRANSPORTATION_ASSEMBLY_POINT => 'assembly_point_transportation_booking',
            self::TRANSPORTATION_START_DATE => 'start_date_transportation_booking',
            self::TRANSPORTATION_START_TIME => 'start_time_transportation_booking',
            self::TRANSPORTATION_END_DATE => 'end_date_transportation_booking',
            self::TRANSPORTATION_END_TIME => 'end_time_transportation_booking',
            self::TRANSPORTATION_CONTACT_NAME => 'contact_name_transportation_booking',
            self::TRANSPORTATION_CONTACT_PHONE => 'contact_phone_transportation_booking',
            self::TRANSPORTATION_ADD_CONTACT_NAME => 'add_contact_name_transportation_booking',
            self::TRANSPORTATION_ADD_CONTACT_PHONE => 'add_contact_phone_transportation_booking',
            self::TRANSPORTATION_NOTES => 'notes_transportation_booking',

            default => null,
        };
    }


    public static function fromFieldName(?string $fieldName): ?self
    {
        return match ($fieldName) {
            'car_booking_start_date' => self::CAR_BOOKING_START_DATE,
            'car_booking_start_time' => self::CAR_BOOKING_START_TIME,
            'car_booking_end_date' => self::CAR_BOOKING_END_DATE,
            'car_booking_end_time' => self::CAR_BOOKING_END_TIME,
            'car_booking_drive_purpose' => self::CAR_BOOKING_DRIVE_PURPOSE,

            'event_name' => self::EVENT_NAME,
            'event_type' => self::EVENT_TYPE,
            'location' => self::LOCATION,
            'supply_date' => self::SUPPLY_DATE,
            'supply_time' => self::SUPPLY_TIME,
            'guests_num' => self::GUESTS_NUM,
            'allergy_btn' => self::ALLERGY_BTN,
            'contact_name' => self::CONTACT_NAME,
            'contact_phone' => self::CONTACT_PHONE,

            'event_name_in_unit_food_booking' => self::IN_UNIT_EVENT_NAME,
            'refreshments_in_unit_food_booking' => self::IN_UNIT_REFRESHMENTS,
            'amount_people_in_unit_food_booking' => self::IN_UNIT_AMOUNT_PEOPLE,
            'food_notes_in_unit_food_booking' => self::IN_UNIT_FOOD_NOTES,
            'supply_date_in_unit_food_booking' => self::IN_UNIT_SUPPLY_DATE,
            'supply_time_in_unit_food_booking' => self::IN_UNIT_SUPPLY_TIME,
            'contact_name_in_unit_food_booking' => self::IN_UNIT_CONTACT_NAME,
            'contact_phone_in_unit_food_booking' => self::IN_UNIT_CONTACT_PHONE,
            'add_contact_name_in_unit_food_booking' => self::IN_UNIT_ADD_CONTACT_NAME,
            'add_contact_phone_in_unit_food_booking' => self::IN_UNIT_ADD_CONTACT_PHONE,

            'event_name_out_unit_food_booking' => self::OUT_UNIT_EVENT_NAME,
            'type_food_out_unit_food_booking' => self::OUT_UNIT_TYPE_FOOD,
            'sandwiches_out_unit_food_booking' => self::OUT_UNIT_SANDWICHES,
            'trays_out_unit_food_booking' => self::OUT_UNIT_TRAYS,
            'food_classification_out_unit_food_booking' => self::OUT_UNIT_FOOD_CLASSIFICATION,
            'dairy_out_unit_food_booking' => self::OUT_UNIT_DAIRY,
            'meat_out_unit_food_booking' => self::OUT_UNIT_MEAT,
            'parve_out_unit_food_booking' => self::OUT_UNIT_PARVE,
            'amount_people_out_unit_food_booking' => self::OUT_UNIT_AMOUNT_PEOPLE,
            'food_notes_out_unit_food_booking' => self::OUT_UNIT_FOOD_NOTES,
            'supply_date_out_unit_food_booking' => self::OUT_UNIT_SUPPLY_DATE,
            'supply_time_out_unit_food_booking' => self::OUT_UNIT_SUPPLY_TIME,
            'supply_address_out_unit_food_booking' => self::OUT_UNIT_SUPPLY_ADDRESS,
            'contact_name_out_unit_food_booking' => self::OUT_UNIT_CONTACT_NAME,
            'contact_phone_out_unit_food_booking' => self::OUT_UNIT_CONTACT_PHONE,
            'add_contact_name_out_unit_food_booking' => self::OUT_UNIT_ADD_CONTACT_NAME,
            'add_contact_phone_out_unit_food_booking' => self::OUT_UNIT_ADD_CONTACT_PHONE,



            'equipment_male' => self::EQUIPMENT_MALE,
          
            'clothing_male' => self::CLOTHING_MALE,
            'footwear_male' => self::FOOTWEAR_MALE,
            'related_products_male' => self::RELATED_PRODUCTS_MALE,
            'certificated_supplier_male' => self::CERTIFICATED_SUPPLIER_MALE,

            'clothing_male_shirt' => self::CLOTHING_MALE_SHIRT,
            'clothing_male_pants' => self::CLOTHING_MALE_PANTS,
            'footwear_male_infantry' => self::FOOTWEAR_MALE_INFANTRY,
            'footwear_male_vegan' => self::FOOTWEAR_MALE_VEGAN,
            'clothing_male_cotton_uniform' => self::CLOTHING_MALE_COTTON_UNIFORM,

            'request_reason_male' => self::REQUEST_REASON_MALE,
            'request_reason_wear_and_tear_male' => self::REQUEST_REASON_WEAR_AND_TEAR_MALE,
            'request_reason_winter_order_male' => self::REQUEST_REASON_WINTER_ORDER_MALE,
            'request_reason_size_change_male' => self::REQUEST_REASON_SIZE_CHANGE_MALE,
            'request_reason_soldier_assignment_male' => self::REQUEST_REASON_SOLDIER_ASSIGNMENT_MALE,
            'request_reason_exemption_male' => self::REQUEST_REASON_EXEMPTION_MALE,

            'quantity_clothing_male' => self::QUANTITY_CLOTHING_MALE,
            'quantity_footwear_male' => self::QUANTITY_FOOTWEAR_MALE,
            'quantity_related_products_male' => self::QUANTITY_RELATED_PRODUCTS_MALE,

            'request_reason_exemption_male_attached_file' => self::REQUEST_REASON_EXEMPTION_MALE_ATTACHED_FILE,

            'equipment_female' => self::EQUIPMENT_FEMALE,

            'clothing_female' => self::CLOTHING_FEMALE,
            'footwear_female' => self::FOOTWEAR_FEMALE,
            'related_products_female' => self::RELATED_PRODUCTS_FEMALE,
            'certificated_supplier_female' => self::CERTIFICATED_SUPPLIER_FEMALE,

            'clothing_female_shirt' => self::CLOTHING_FEMALE_SHIRT,
            'clothing_female_pants' => self::CLOTHING_FEMALE_PANTS,
            'clothing_female_skirt' => self::CLOTHING_FEMALE_SKIRT,
            'footwear_female_shoes' => self::FOOTWEAR_FEMALE_SHOES,
            'footwear_female_vegan' => self::FOOTWEAR_FEMALE_VEGAN,
            'footwear_female_infantry' => self::FOOTWEAR_FEMALE_INFANTRY,
            'clothing_female_cotton_uniform' => self::CLOTHING_FEMALE_COTTON_UNIFORM,

            'request_reason_female' => self::REQUEST_REASON_FEMALE,
            'request_reason_wear_and_tear_female' => self::REQUEST_REASON_WEAR_AND_TEAR_FEMALE,
            'request_reason_winter_order_female' => self::REQUEST_REASON_WINTER_ORDER_FEMALE,
            'request_reason_size_change_female' => self::REQUEST_REASON_SIZE_CHANGE_FEMALE,
            'request_reason_soldier_assignment_female' => self::REQUEST_REASON_SOLDIER_ASSIGNMENT_FEMALE,
            'request_reason_exemption_female' => self::REQUEST_REASON_EXEMPTION_FEMALE,

            'quantity_clothing_female' => self::QUANTITY_CLOTHING_FEMALE,
            'quantity_footwear_female' => self::QUANTITY_FOOTWEAR_FEMALE,
            'quantity_related_products_female' => self::QUANTITY_RELATED_PRODUCTS_FEMALE,

            'request_reason_exemption_female_attached_file' => self::REQUEST_REASON_EXEMPTION_FEMALE_ATTACHED_FILE,

            'company_name_car_maintanence' => self::CAR_MAINTANENCE_COMPANY_NAME,
            'model_car_maintanence' => self::CAR_MAINTANENCE_MODEL,
            'vehicle_number_car_maintanence' => self::CAR_MAINTANENCE_VEHICLE_NUMBER,
            'car_code_car_maintanence' => self::CAR_MAINTANENCE_CAR_CODE,
            'fuel_code_car_maintanence' => self::CAR_MAINTANENCE_FUEL_CODE,
            'current_kilometer_car_maintanence' => self::CAR_MAINTANENCE_CURRENT_KILOMETER,
            'core_problem_car_maintanence' => self::CAR_MAINTANENCE_CORE_PROBLEM,
            'garage_name_car_maintanence' => self::CAR_MAINTANENCE_GARAGE_NAME,
            'garage_address_car_maintanence' => self::CAR_MAINTANENCE_GARAGE_ADDRESS,
            'date_set_car_maintanence' => self::CAR_MAINTANENCE_DATE_SET,
            'comment_car_maintanence' => self::CAR_MAINTANENCE_COMMENT,

            'barber_date' => self::BARBER_DATE,
            'barber_time' => self::BARBER_TIME,

            'lecture_room_date' => self::LECTURE_ROOM_DATE,
            'lecture_room_time' => self::LECTURE_ROOM_TIME,
            'lecture_room_user_amount' => self::LECTURE_ROOM_USER_AMOUNT,
            'lecture_room_event_purpose' => self::LECTURE_ROOM_EVENT_PURPOSE,

            'small_command_room_date' => self::SMALL_COMMAND_ROOM_DATE,
            'small_command_room_time' => self::SMALL_COMMAND_ROOM_TIME,
            'small_command_room_user_amount' => self::SMALL_COMMAND_ROOM_USER_AMOUNT,
            'small_command_room_event_purpose' => self::SMALL_COMMAND_ROOM_EVENT_PURPOSE,

            'tech_hamama_first_date' => self::TECH_HAMAMA_FIRST_DATE,
            'tech_hamama_first_time' => self::TECH_HAMAMA_FIRST_TIME,
            'tech_hamama_first_user_amount' => self::TECH_HAMAMA_FIRST_USER_AMOUNT,
            'tech_hamama_first_event_purpose' => self::TECH_HAMAMA_FIRST_EVENT_PURPOSE,

            'tech_hamama_second_date' => self::TECH_HAMAMA_SECOND_DATE,
            'tech_hamama_second_time' => self::TECH_HAMAMA_SECOND_TIME,
            'tech_hamama_second_user_amount' => self::TECH_HAMAMA_SECOND_USER_AMOUNT,
            'tech_hamama_second_event_purpose' => self::TECH_HAMAMA_SECOND_EVENT_PURPOSE,

            'building_number_construction_booking' => self::CONSTRUCTION_BUILDING_NUMBER,
            'notes_construction_booking' => self::CONSTRUCTION_NOTES,
            'images_construction_booking' => self::CONSTRUCTION_IMAGES,
            'fault_type_construction_booking' => self::CONSTRUCTION_FAULT_TYPE,
            'electricity_construction_booking' => self::CONSTRUCTION_ELECTRICITY,
            'structure_construction_booking' => self::CONSTRUCTION_STRUCTURE,
            'ac_construction_booking' => self::CONSTRUCTION_AC,
            'plumbing_construction_booking' => self::CONSTRUCTION_PLUMBING,

            'order_type_transportation_booking' => self::TRANSPORTATION_ORDER_TYPE,
            'cargo_transportation_booking' => self::TRANSPORTATION_CARGO,
            'vehicle_type_transportation_booking' => self::TRANSPORTATION_VEHICLE_TYPE,
            'assembly_point_transportation_booking' => self::TRANSPORTATION_ASSEMBLY_POINT,
            'start_date_transportation_booking' => self::TRANSPORTATION_START_DATE,
            'start_time_transportation_booking' => self::TRANSPORTATION_START_TIME,
            'end_date_transportation_booking' => self::TRANSPORTATION_END_DATE,
            'end_time_transportation_booking' => self::TRANSPORTATION_END_TIME,
            'contact_name_transportation_booking' => self::TRANSPORTATION_CONTACT_NAME,
            'contact_phone_transportation_booking' => self::TRANSPORTATION_CONTACT_PHONE,
            'add_contact_name_transportation_booking' => self::TRANSPORTATION_ADD_CONTACT_NAME,
            'add_contact_phone_transportation_booking' => self::TRANSPORTATION_ADD_CONTACT_PHONE,
            'notes_transportation_booking' => self::TRANSPORTATION_NOTES,

            default => null,
        };
    }

    /** The DB id looked up by unique field_name; memoized. */
    public function getFormFieldId(): ?int
    {
        static $cache = [];

        $formFieldName = $this->getFormFieldName();

        if (!$formFieldName) {
            return null;
        }

        if (!array_key_exists($formFieldName, $cache)) {
            $cache[$formFieldName] = FormField::query()
                ->where('field_name', $formFieldName)
                ->value('id');
        }

        return $cache[$formFieldName];
    }

    /** Child form field ids of a parent. */
    public static function getChildFormFieldIdsByFormFieldId(?int $formFieldId): array
    {
        if (empty($formFieldId)) {
            return [];
        }

        return FormField::query()
            ->where('form_field_id', $formFieldId)
            ->pluck('id')
            ->toArray();
    }


    /** Option field ids of a form field. */
    public static function getOptionFieldIdsByFormFieldId(?int $formFieldId): array
    {
 
        return OptionField::query()
            ->where('form_field_id', $formFieldId)
            ->pluck('id')
            ->toArray();
    }

    /** Root selectable form fields of a unit's booking type. */
    public static function getSelectableRootFormFields(
        int $unitId,
        int $bookingTypeId,
        array $relations = []
    ) {
        return FormField::query()
            ->with($relations)
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->whereNull('form_field_id')
            ->whereIn('field_type_id', FieldTypeEnum::selectedFieldTypeIds())
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** Form field ids of a booking type. */
    public static function getFormFieldIdsByBookingTypeId(int $bookingTypeId): array
    {
        return FormField::where('booking_type_id', $bookingTypeId)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();
    }

    /** Option field ids of a booking type. */
    public static function getOptionFieldIdsByBookingTypeId(
        int $bookingTypeId,
        ?int $formFieldId = null
    ): array {
        return OptionField::query()
            ->whereHas('formField', function ($query) use ($bookingTypeId) {
                $query->where('booking_type_id', $bookingTypeId);
            })
            ->when($formFieldId !== null, function ($query) use ($formFieldId) {
                $query->where('form_field_id', $formFieldId);
            })
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->toArray();
    }



    public function getFormFieldSubDisplayName(): ?string
    {
        return match ($this) {
            self::CAR_BOOKING_START_DATE => 'בחירת תאריך התחלה',
            self::CAR_BOOKING_START_TIME => 'בחירת שעת התחלה',
            self::CAR_BOOKING_END_DATE => 'בחירת תאריך סיום',
            self::CAR_BOOKING_END_TIME => 'בחירת שעת סיום',
            self::CAR_BOOKING_DRIVE_PURPOSE => 'בחירת מטרת נסיעה',
            self::CAR_BOOKING_NUMBER_OF_PASSENGERS => 'בחירת מספר נוסעים',
            self::EVENT_NAME => 'הזנת שם אירוע',
            self::EVENT_TYPE => 'בחירת סוג אירוע',
            self::LOCATION => 'בחירת מיקום',
            self::SUPPLY_DATE => 'בחירת תאריך אספקה',
            self::SUPPLY_TIME => 'בחירת שעת אספקה',
            self::GUESTS_NUM => 'הזנת כמות מוזמנים',
            self::ALLERGY_BTN => 'בחירת מידע על רגישויות',
            self::CONTACT_NAME => 'הזנת שם איש קשר',
            self::CONTACT_PHONE => 'הזנת טלפון איש קשר',
            self::CLOTHING_MALE => 'בחירת סוג ביגוד',
            self::FOOTWEAR_MALE => 'בחירת סוג הנעלה',
            self::RELATED_PRODUCTS_MALE => 'בחירת מוצרים נלווים',
            self::CERTIFICATED_SUPPLIER_MALE => 'הזנת מספר תעודה ספק',

            self::CLOTHING_FEMALE => 'בחירת סוג ביגוד',
            self::FOOTWEAR_FEMALE => 'בחירת סוג הנעלה',
            self::RELATED_PRODUCTS_FEMALE => 'בחירת מוצרים נלווים',
            self::CERTIFICATED_SUPPLIER_FEMALE => 'הזנת מספר תעודה ספק',
            self::CAR_MAINTANENCE_COMPANY_NAME => 'בחירת חברת ליסינג',
            self::CAR_MAINTANENCE_MODEL => 'הזנת דגם רכב',
            self::CAR_MAINTANENCE_VEHICLE_NUMBER => 'הזנת מספר רכב',
            self::CAR_MAINTANENCE_CAR_CODE => 'הזנת קוד רכב',
            self::CAR_MAINTANENCE_FUEL_CODE => 'הזנת קוד דלק',
            self::CAR_MAINTANENCE_CURRENT_KILOMETER => 'הזנת קילומטר נוכחי',
            self::CAR_MAINTANENCE_CORE_PROBLEM => 'בחירת מהות התקלה',
            self::CAR_MAINTANENCE_GARAGE_NAME => 'הזנת שם המוסך',
            self::CAR_MAINTANENCE_GARAGE_ADDRESS => 'הזנת כתובת המוסך',
            self::CAR_MAINTANENCE_DATE_SET => 'בחירת תאריך שנקבע עם המוסך',
            self::CAR_MAINTANENCE_COMMENT => 'הזנת הערות',
            self::LECTURE_ROOM_EVENT_PURPOSE => 'הזנת מטרת האירוע',

            self::IN_UNIT_REFRESHMENTS => 'בחירת סוג כיבוד',

            self::OUT_UNIT_TYPE_FOOD => 'בחירת סוג מזון',
            self::OUT_UNIT_SANDWICHES => 'בחירת אפשרות אספקה עבור כריכים',
            self::OUT_UNIT_TRAYS => 'בחירת אפשרות אספקה עבור חמגשיות',
            self::OUT_UNIT_FOOD_CLASSIFICATION => 'בחירת סיווג המזון',
            self::OUT_UNIT_DAIRY => 'בחירת רגישויות עבור חלבי',
            self::OUT_UNIT_MEAT => 'בחירת רגישויות עבור בשרי',
            self::OUT_UNIT_PARVE => 'בחירת רגישויות עבור פרווה',

            self::CONSTRUCTION_IMAGES => 'צירוף תמונות של התקלה',
            self::CONSTRUCTION_FAULT_TYPE => 'בחירת סוג תקלה',
            self::CONSTRUCTION_ELECTRICITY => 'בחירת פרטי התקלה בחשמל',
            self::CONSTRUCTION_STRUCTURE => 'בחירת פרטי התקלה בבינוי',
            self::CONSTRUCTION_AC => 'בחירת פרטי התקלה במיזוג',
            self::CONSTRUCTION_PLUMBING => 'בחירת פרטי התקלה באינסטלציה',

            self::TRANSPORTATION_ORDER_TYPE => 'בחירת סוג הזמנה',
            self::TRANSPORTATION_CARGO => 'בחירת סוג מטען',
            self::TRANSPORTATION_VEHICLE_TYPE => 'בחירת סוג רכב',
            self::TRANSPORTATION_ASSEMBLY_POINT => 'בחירת מקום התייצבות',
            self::TRANSPORTATION_START_DATE => 'בחירת תאריך התחלה',
            self::TRANSPORTATION_START_TIME => 'בחירת שעת התחלה',
            self::TRANSPORTATION_END_DATE => 'בחירת תאריך סיום',
            self::TRANSPORTATION_END_TIME => 'בחירת שעת סיום',
            self::TRANSPORTATION_NOTES => 'בחירת הערה',

            self::CLOTHING_MALE_SHIRT => 'בחירת מידת חולצה',
            self::CLOTHING_MALE_PANTS => 'בחירת מידת מכנסיים',
            self::FOOTWEAR_MALE_INFANTRY => 'בחירת מידת נעל חי"ר',
            self::FOOTWEAR_MALE_VEGAN => 'בחירת מידת נעל בהתאמה אישית',
            self::CLOTHING_MALE_COTTON_UNIFORM => 'בחירת מידת מדי כותנה',

            self::REQUEST_REASON_MALE => 'בחירת סיבת הבקשה',
            self::REQUEST_REASON_WEAR_AND_TEAR_MALE => 'בלאי',
            self::REQUEST_REASON_WINTER_ORDER_MALE => 'הזמנת חורף',
            self::REQUEST_REASON_SIZE_CHANGE_MALE => 'שינוי מידה',
            self::REQUEST_REASON_SOLDIER_ASSIGNMENT_MALE => 'שיוך חיילי',
            self::REQUEST_REASON_EXEMPTION_MALE => 'פטור',

            self::CLOTHING_FEMALE_SHIRT => 'בחירת מידת חולצה',
            self::CLOTHING_FEMALE_PANTS => 'בחירת מידת מכנסיים',
            self::CLOTHING_FEMALE_SKIRT => 'בחירת מידת חצאית',
            self::FOOTWEAR_FEMALE_SHOES => 'בחירת מידת נעל',
            self::FOOTWEAR_FEMALE_VEGAN => 'בחירת מידת נעל בהתאמה אישית',
            self::FOOTWEAR_FEMALE_INFANTRY => 'בחירת מידת נעל חי"ר',
            self::CLOTHING_FEMALE_COTTON_UNIFORM => 'בחירת מידת מדי כותנה',

            self::REQUEST_REASON_FEMALE => 'בחירת סיבת הבקשה',
            self::REQUEST_REASON_WEAR_AND_TEAR_FEMALE => 'בלאי',
            self::REQUEST_REASON_WINTER_ORDER_FEMALE => 'הזמנת חורף',
            self::REQUEST_REASON_SIZE_CHANGE_FEMALE => 'שינוי מידה',
            self::REQUEST_REASON_SOLDIER_ASSIGNMENT_FEMALE => 'שיוך חיילי',
            self::REQUEST_REASON_EXEMPTION_FEMALE => 'פטור',

            default => null,
        };
    }

    public function getSettingsDisplayName(): ?string
    {
        return match ($this) {
            self::CAR_BOOKING_START_DATE => 'בחירת תאריך התחלה',
            self::CAR_BOOKING_START_TIME => 'בחירת שעת התחלה',
            self::CAR_BOOKING_END_DATE => 'בחירת תאריך סיום',
            self::CAR_BOOKING_END_TIME => 'בחירת שעת סיום',
            self::CAR_BOOKING_DRIVE_PURPOSE => 'הגדרת שדה מטרת נסיעה',
            self::CAR_BOOKING_NUMBER_OF_PASSENGERS => 'הגדרת שדה מספר נוסעים',
            self::EVENT_NAME => 'בחירת שם אירוע',
            self::LOCATION => 'סוגי אוכל',
            self::SUPPLY_DATE => 'בתחירת תאריך אספקה',
            self::SUPPLY_TIME => 'בחירת שעת אספקה',
            self::GUESTS_NUM => 'בחירת כמות מוזמנים',
            self::ALLERGY_BTN => 'הגדרת סוגי רגישות',
            self::CONTACT_NAME => ' שם איש קשר',
            self::CONTACT_PHONE => 'מספר טלפון של איש קשר',
            self::CLOTHING_MALE => 'הגדרת ביגוד גברים',
            self::FOOTWEAR_MALE => 'הגדרת הנעלה גברים',
            self::RELATED_PRODUCTS_MALE => 'הגדרת מוצרים נלווים גברים',
            self::CERTIFICATED_SUPPLIER_MALE => 'תעודת ספק גברים',
            self::REQUEST_REASON_MALE => 'הגדרת סיבת הבקשה גברים',

            self::CLOTHING_FEMALE => 'הגדרת ביגוד נשים',
            self::FOOTWEAR_FEMALE => 'הגדרת הנעלה נשים',
            self::RELATED_PRODUCTS_FEMALE => 'הגדרת מוצרים נלווים נשים',
            self::CERTIFICATED_SUPPLIER_FEMALE => 'תעודת ספק נשים',
            self::REQUEST_REASON_FEMALE => 'הגדרת סיבת הבקשה נשים',

            self::LECTURE_ROOM_EVENT_PURPOSE => 'מטרת האירוע',
            default => null,
        };
    }

}

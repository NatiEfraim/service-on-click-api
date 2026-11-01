<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\PayloadSectionEnum;
use App\Enums\UnitEnum;
use App\Models\FormField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FormFieldSeeder extends Seeder
{

    public function run(): void
    {

        try {

            DB::beginTransaction();

            $this->seedFormFields();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('FormFieldSeeder failed. message' . $e->getMessage());
        }

    }

    private function seedFormFields(): void
    {
        $this->seedCarBookingFields();

        $this->seedInUnitFoodBookingFields();

        $this->seedOutUnitFoodBookingFields();

        $this->seedEquipmentMaleBookingFields();

        $this->seedEquipmentFemaleBookingFields();

        $this->seedCarMaintenanceBookingFields();

        $this->seedBarberBookingFields();

        $this->seedConstructionBookingFields();

        $this->seedTransportationBookingFields();
    }

    private function seedCarBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::CAR_BOOKING->getBookingTypeId(),
            $this->carBookingFields()
        );
    }

    private function seedCarMaintenanceBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId(),
            $this->carMaintenanceBookingFields()
        );
    }

    private function seedEquipmentMaleBookingFields(): void
    {

        $bookingTypeId = BookingTypeEnum::EQUIPMENT_BOOKING_MALE->getBookingTypeId();

        $this->createFormFields(
            $bookingTypeId,
            $this->equipmentMaleBookingFields()
        );

        $this->createEquipmentMaleCategoryFields($bookingTypeId);

        $this->createEquipmentMalePromotedItemFields($bookingTypeId);

        $this->createEquipmentMaleQuantityFields($bookingTypeId);

        $this->createEquipmentMaleRequestReasonFields($bookingTypeId);

        $this->createEquipmentAttachedFileField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::REQUEST_REASON_EXEMPTION_MALE->getFormFieldName(),
            fileFieldName: FormFieldEnum::REQUEST_REASON_EXEMPTION_MALE_ATTACHED_FILE->getFormFieldName(),
            requiredMessage: 'יש לצרף קובץ עבור פטור',
        );
    }

    private function seedEquipmentFemaleBookingFields(): void
    {
        $bookingTypeId = BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE->getBookingTypeId();

        $this->createFormFields(
            $bookingTypeId,
            $this->equipmentFemaleBookingFields()
        );

        $this->createEquipmentFemaleCategoryFields($bookingTypeId);

        $this->createEquipmentFemalePromotedItemFields($bookingTypeId);

        $this->createEquipmentFemaleQuantityFields($bookingTypeId);

        $this->createEquipmentFemaleRequestReasonFields($bookingTypeId);

        $this->createEquipmentAttachedFileField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::REQUEST_REASON_EXEMPTION_FEMALE->getFormFieldName(),
            fileFieldName: FormFieldEnum::REQUEST_REASON_EXEMPTION_FEMALE_ATTACHED_FILE->getFormFieldName(),
            requiredMessage: 'יש לצרף קובץ עבור פטור',
        );
    }


    private function seedInUnitFoodBookingFields(): void
    {

        $bookingTypeId = BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId();

        $this->createFormFields(
            $bookingTypeId,
            $this->inUnitFoodBookingFields()
        );
    }

    private function seedOutUnitFoodBookingFields(): void
    {

        $bookingTypeId = BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId();

        $this->createFormFields(
            $bookingTypeId,
            $this->outUnitFoodBookingFields()
        );

        $this->createOutUnitFoodTypeFoodFields($bookingTypeId);

        $this->createOutUnitFoodSensitivityFields($bookingTypeId);

    }

    private function seedBarberBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::BARBER_BOOKING->getBookingTypeId(),
            $this->barberBookingFields()
        );
    }

    private function seedConstructionBookingFields(): void
    {
        $bookingTypeId = BookingTypeEnum::CONSTRUCTION_BOOKING->getBookingTypeId();

        $this->createFormFields(
            $bookingTypeId,
            $this->constructionBookingFields()
        );

        $this->createConstructionFaultCategoryFields($bookingTypeId);
    }

    /** Seed TRANSPORTATION_BOOKING form fields - the 4 selects are flat root fields, leaves live in OptionFieldSeeder. */
    private function seedTransportationBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId(),
            $this->transportationBookingFields()
        );
    }

    private function seedLectureRoomBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::LECTURE_ROOM_BOOKING->getBookingTypeId(),
            $this->lectureRoomBookingFields()
        );
    }

    private function seedSmallCommandRoomBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::SMALL_COMMAND_ROOM_BOOKING->getBookingTypeId(),
            $this->smallCommandRoomBookingFields()
        );
    }

    private function seedTechHamamaFirstBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::TECH_HAMAMA_FIRST_BOOKING->getBookingTypeId(),
            $this->techHamamaFirstBookingFields()
        );
    }

    private function seedTechHamamaSecondBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::TECH_HAMAMA_SECOND_BOOKING->getBookingTypeId(),
            $this->techHamamaSecondBookingFields()
        );
    }



    private function seedFoodBookingFields(): void
    {
        $this->createFormFields(
            BookingTypeEnum::FOOD_BOOKING->getBookingTypeId(),
            $this->foodBookingFields()
        );
    }

    // Helper functions
    private function createFormFields(int $bookingTypeId, array $formFields): void
    {
        foreach ($formFields as $field) {
            FormField::updateOrCreate(
                [
                    'booking_type_id' => $bookingTypeId,
                    'unit_id' => $field['unit_id'],
                    'field_name' => $field['field_name'],
                ],
                [
                    'field_display_name' => $field['field_display_name'],
                    'field_type_id' => $field['field_type_id'],
                    'form_field_id' => $field['form_field_id'] ?? null,
                    'is_input' => $field['is_input'] ?? false,
                    'field_validations' => $field['field_validations'] ?? null,
                    'editable' => $field['editable'] ?? true,
                    'is_required' => $field['is_required'] ?? true,
                    'payload_section_id' => $field['payload_section_id'] ?? PayloadSectionEnum::NONE->value,

                ]
            );
        }

    }

    // CAR-BOOKING
    private function carBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_BOOKING_DRIVE_PURPOSE->getFormFieldName(),
                'field_display_name' => 'מטרת נסיעה',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מטרת נסיעה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_BOOKING_START_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך התחלה',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך התחלה',
                    'date' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_BOOKING_START_TIME->getFormFieldName(),
                'field_display_name' => 'שעת התחלה',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעת התחלה',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_BOOKING_END_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך סיום',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך סיום',
                    'date' => 'פורמט אינו תקין',
                    'after_or_equal:start_date' => 'תאריך סיום לאחר תאריך התחלה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_BOOKING_END_TIME->getFormFieldName(),
                'field_display_name' => 'שעת סיום',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעת סיום',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

        ];
    }

    // IN-UNIT-FOOD-BOOKING
    /** IN_UNIT_FOOD_BOOKING form fields. */
    private function inUnitFoodBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_EVENT_NAME->getFormFieldName(),
                'field_display_name' => 'שם אירוע',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם אירוע',
                    'string' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_REFRESHMENTS->getFormFieldName(),
                'field_display_name' => 'כיבוד',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => null,
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_AMOUNT_PEOPLE->getFormFieldName(),
                'field_display_name' => 'כמות אנשים',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק כמות אנשים',
                    'integer' => 'כמות אנשים חייבת להיות מספר שלם',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך אספקה',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך אספקה',
                    'date' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_SUPPLY_TIME->getFormFieldName(),
                'field_display_name' => 'שעת אספקה',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעת אספקה',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_CONTACT_NAME->getFormFieldName(),
                'field_display_name' => 'שם איש קשר',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם איש קשר',
                    'string' => 'פורמט אינו תקין',
                    'regex:/^[a-zA-Z0-9א-ת\s]+$/' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_CONTACT_PHONE->getFormFieldName(),
                'field_display_name' => 'טלפון איש קשר',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר טלפון איש קשר',
                    'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/' => 'מספר טלפון לא תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_ADD_CONTACT_NAME->getFormFieldName(),
                'field_display_name' => 'שם איש קשר נוסף',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם איש קשר נוסף',
                    'string' => 'פורמט אינו תקין',
                    'regex:/^[a-zA-Z0-9א-ת\s]+$/' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_ADD_CONTACT_PHONE->getFormFieldName(),
                'field_display_name' => 'טלפון איש קשר נוסף',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר טלפון איש קשר נוסף',
                    'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/' => 'מספר טלפון לא תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::IN_UNIT_FOOD_NOTES->getFormFieldName(),
                'field_display_name' => 'הערות',
                'field_type_id' => FieldTypeEnum::TEXT_AREA->value,
                'field_validations' => json_encode([
                    'nullable' => true,
                    'string' => 'ערך השדה אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 0,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
        ];
    }


    // OUT_UNIT_FOOD_BOOKING

    /** OUT_UNIT_FOOD_BOOKING form fields. */
    private function outUnitFoodBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_EVENT_NAME->getFormFieldName(),
                'field_display_name' => 'שם אירוע',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם אירוע',
                    'string' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_TYPE_FOOD->getFormFieldName(),
                'field_display_name' => 'סוג מזון',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => null,
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_FOOD_CLASSIFICATION->getFormFieldName(),
                'field_display_name' => 'סיווג המזון',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => null,
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_AMOUNT_PEOPLE->getFormFieldName(),
                'field_display_name' => 'כמות אנשים',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק כמות אנשים',
                    'numeric' => 'פורמט אינו תקין',
                    'min:1' => 'כמות חייבת להיות גדולה מ-0',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],


            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך אספקה',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך אספקה',
                    'date' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_SUPPLY_TIME->getFormFieldName(),
                'field_display_name' => 'שעת אספקה',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעת אספקה',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_SUPPLY_ADDRESS->getFormFieldName(),
                'field_display_name' => 'כתובת אספקה',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק כתובת אספקה',
                    'string' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_CONTACT_NAME->getFormFieldName(),
                'field_display_name' => 'שם איש קשר',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם איש קשר',
                    'string' => 'פורמט אינו תקין',
                    'regex:/^[a-zA-Z0-9א-ת\s]+$/' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_CONTACT_PHONE->getFormFieldName(),
                'field_display_name' => 'טלפון איש קשר',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר טלפון איש קשר',
                    'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/' => 'מספר טלפון לא תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_ADD_CONTACT_NAME->getFormFieldName(),
                'field_display_name' => 'שם איש קשר נוסף',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם איש קשר נוסף',
                    'string' => 'פורמט אינו תקין',
                    'regex:/^[a-zA-Z0-9א-ת\s]+$/' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_ADD_CONTACT_PHONE->getFormFieldName(),
                'field_display_name' => 'טלפון איש קשר נוסף',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר טלפון איש קשר נוסף',
                    'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/' => 'מספר טלפון לא תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],

            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::OUT_UNIT_FOOD_NOTES->getFormFieldName(),
                'field_display_name' => 'הערות',
                'field_type_id' => FieldTypeEnum::TEXT_AREA->value,
                'field_validations' => json_encode([
                    'nullable' => true,
                    'string' => 'ערך שאינו טקסט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 0,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
        ];
    }


    /** OUT_UNIT_FOOD_BOOKING type food child fields: type_food -> sandwiches / trays. */
    private function createOutUnitFoodTypeFoodFields(int $bookingTypeId): void
    {
        $this->createOutUnitFoodChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::OUT_UNIT_TYPE_FOOD->getFormFieldName(),
            childFieldName: FormFieldEnum::OUT_UNIT_SANDWICHES->getFormFieldName(),
            childDisplayName: 'כריכים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
            fieldTypeId: FieldTypeEnum::SELECT->value,
        );

        $this->createOutUnitFoodChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::OUT_UNIT_TYPE_FOOD->getFormFieldName(),
            childFieldName: FormFieldEnum::OUT_UNIT_TRAYS->getFormFieldName(),
            childDisplayName: 'חמגשיות',
            payloadSectionId: PayloadSectionEnum::NONE->value,
            fieldTypeId: FieldTypeEnum::SELECT->value,
        );
    }


    /** OUT_UNIT_FOOD_BOOKING sensitivity child fields: sensitivities -> dairy / meat / parve. */
    private function createOutUnitFoodSensitivityFields(int $bookingTypeId): void
    {
        $this->createOutUnitFoodChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::OUT_UNIT_FOOD_CLASSIFICATION->getFormFieldName(),
            childFieldName: FormFieldEnum::OUT_UNIT_DAIRY->getFormFieldName(),
            childDisplayName: 'חלבי',
            payloadSectionId: PayloadSectionEnum::NONE->value,
            fieldTypeId: FieldTypeEnum::MULTI_SELECT->value,
        );

        $this->createOutUnitFoodChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::OUT_UNIT_FOOD_CLASSIFICATION->getFormFieldName(),
            childFieldName: FormFieldEnum::OUT_UNIT_MEAT->getFormFieldName(),
            childDisplayName: 'בשרי',
            payloadSectionId: PayloadSectionEnum::NONE->value,
            fieldTypeId: FieldTypeEnum::MULTI_SELECT->value,
        );

        $this->createOutUnitFoodChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::OUT_UNIT_FOOD_CLASSIFICATION->getFormFieldName(),
            childFieldName: FormFieldEnum::OUT_UNIT_PARVE->getFormFieldName(),
            childDisplayName: 'פרווה',
            payloadSectionId: PayloadSectionEnum::NONE->value,
            fieldTypeId: FieldTypeEnum::MULTI_SELECT->value,
        );
    }

    /** Create one OUT_UNIT_FOOD child field. */
    private function createOutUnitFoodChildFormField(
        int $bookingTypeId,
        string $parentFieldName,
        string $childFieldName,
        string $childDisplayName,
        ?int $payloadSectionId = PayloadSectionEnum::NONE->value,
        ?int $fieldTypeId = FieldTypeEnum::MULTI_SELECT->value,
    ): void {
        $parentFormField = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->value)
            ->where('field_name', $parentFieldName)
            ->first();

        if (!$parentFormField) {
            return;
        }

        $field = [
            'unit_id' => UnitEnum::H->value,
            'field_name' => $childFieldName,
            'field_display_name' => $childDisplayName,
            'field_type_id' => $fieldTypeId,
            'form_field_id' => $parentFormField->id,
            'field_validations' => json_encode([
                'required' => 'יש לבחור ' . $childDisplayName,
            ], JSON_UNESCAPED_UNICODE),
            'editable' => 0,
            'is_required' => 1,
            'is_input' => false,
            'payload_section_id' => $payloadSectionId,
        ];

        FormField::updateOrCreate(
            [
                'booking_type_id' => $bookingTypeId,
                'unit_id' => $field['unit_id'],
                'field_name' => $field['field_name'],
            ],
            [
                'field_display_name' => $field['field_display_name'],
                'field_type_id' => $field['field_type_id'],
                'form_field_id' => $field['form_field_id'],
                'is_input' => $field['is_input'],
                'field_validations' => $field['field_validations'],
                'editable' => $field['editable'],
                'is_required' => $field['is_required'],
                'payload_section_id' => $field['payload_section_id'],
            ]
        );
    }


    // EQUIPMENT-BOOKING-MALE
    private function equipmentMaleBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::EQUIPMENT_MALE->getFormFieldName(),
                'field_display_name' => 'ציוד',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => null,
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            // Root field: a sibling of equipment_male, not its child.
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::REQUEST_REASON_MALE->getFormFieldName(),
                'field_display_name' => 'סיבת הבקשה',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק סיבת הבקשה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
        ];
    }



    /** EQUIPMENT_BOOKING_MALE category child fields: equipment_male -> clothing/footwear/related_products. */
    private function createEquipmentMaleCategoryFields(int $bookingTypeId): void
    {
        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::EQUIPMENT_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_MALE->getFormFieldName(),
            childDisplayName: 'ביגוד',
            childValidationMessage: 'יש לספק סוג ביגוד',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::EQUIPMENT_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::FOOTWEAR_MALE->getFormFieldName(),
            childDisplayName: 'הנעלה',
            childValidationMessage: 'יש לספק סוג הנעלה',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::EQUIPMENT_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::RELATED_PRODUCTS_MALE->getFormFieldName(),
            childDisplayName: 'מוצרים נלווים',
            childValidationMessage: 'יש לספק מוצרים נלווים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );
    }

    /** EQUIPMENT_BOOKING_MALE promoted item child fields; sizes still live one level below as OptionFields. */
    private function createEquipmentMalePromotedItemFields(int $bookingTypeId): void
    {
        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_MALE_SHIRT->getFormFieldName(),
            childDisplayName: 'חולצת בנים',
            childValidationMessage: 'יש לספק מידת חולצת בנים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_MALE_PANTS->getFormFieldName(),
            childDisplayName: 'מכנסי בנים',
            childValidationMessage: 'יש לספק מידת מכנסי בנים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        // Cotton uniform sits under clothing; its children and sizes are OptionFields.
        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_MALE_COTTON_UNIFORM->getFormFieldName(),
            childDisplayName: 'מדי כותנה',
            childValidationMessage: 'יש לספק פרטי מדי כותנה',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::FOOTWEAR_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::FOOTWEAR_MALE_INFANTRY->getFormFieldName(),
            childDisplayName: 'נעלי חי"ר',
            childValidationMessage: 'יש לספק מידת נעלי חי"ר',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::FOOTWEAR_MALE->getFormFieldName(),
            childFieldName: FormFieldEnum::FOOTWEAR_MALE_VEGAN->getFormFieldName(),
            childDisplayName: 'נעלים בהתאמה אישית',
            childValidationMessage: 'יש לספק מידת נעלים טבעוניות',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );
    }

    /** EQUIPMENT_BOOKING_MALE child quantity fields. */
    private function createEquipmentMaleQuantityFields(int $bookingTypeId): void
    {
        $this->createQuantityChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_MALE->getFormFieldName(),
            quantityFieldName: FormFieldEnum::QUANTITY_CLOTHING_MALE->getFormFieldName(),
            payloadSectionId: PayloadSectionEnum::ITEMS->value,
        );

        $this->createQuantityChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::FOOTWEAR_MALE->getFormFieldName(),
            quantityFieldName: FormFieldEnum::QUANTITY_FOOTWEAR_MALE->getFormFieldName(),
            payloadSectionId: PayloadSectionEnum::ITEMS->value,
        );

        $this->createQuantityChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::RELATED_PRODUCTS_MALE->getFormFieldName(),
            quantityFieldName: FormFieldEnum::QUANTITY_RELATED_PRODUCTS_MALE->getFormFieldName(),
            payloadSectionId: PayloadSectionEnum::ITEMS->value,
        );
    }

    /** EQUIPMENT_BOOKING_MALE request reason leaf fields; the 5 leaves carry no OptionField of their own. */
    private function createEquipmentMaleRequestReasonFields(int $bookingTypeId): void
    {
        // request_reason_male is a root field created earlier; only its leaves are created here.
        foreach ($this->equipmentMaleRequestReasonLeafFields() as $leafField) {
            $this->createEquipmentCategoryChildFormField(
                bookingTypeId: $bookingTypeId,
                parentFieldName: FormFieldEnum::REQUEST_REASON_MALE->getFormFieldName(),
                childFieldName: $leafField['enum']->getFormFieldName(),
                childDisplayName: $leafField['display_name'],
                childValidationMessage: "יש לספק {$leafField['display_name']}",
                payloadSectionId: PayloadSectionEnum::SELECTS->value,
            );
        }
    }

    /** @return array<int, array{enum: FormFieldEnum, display_name: string}> The 5 request reason leaves (male). */
    private function equipmentMaleRequestReasonLeafFields(): array
    {
        return [
            ['enum' => FormFieldEnum::REQUEST_REASON_WEAR_AND_TEAR_MALE, 'display_name' => 'בלאי'],
            ['enum' => FormFieldEnum::REQUEST_REASON_WINTER_ORDER_MALE, 'display_name' => 'הזמנת חורף'],
            ['enum' => FormFieldEnum::REQUEST_REASON_SIZE_CHANGE_MALE, 'display_name' => 'שינוי מידה'],
            ['enum' => FormFieldEnum::REQUEST_REASON_SOLDIER_ASSIGNMENT_MALE, 'display_name' => 'שיוך חיילי'],
            ['enum' => FormFieldEnum::REQUEST_REASON_EXEMPTION_MALE, 'display_name' => 'פטור'],
        ];
    }

    /** @return array<int, array{enum: FormFieldEnum, display_name: string}> The 5 request reason leaves (female). */
    private function equipmentFemaleRequestReasonLeafFields(): array
    {
        return [
            ['enum' => FormFieldEnum::REQUEST_REASON_WEAR_AND_TEAR_FEMALE, 'display_name' => 'בלאי'],
            ['enum' => FormFieldEnum::REQUEST_REASON_WINTER_ORDER_FEMALE, 'display_name' => 'הזמנת חורף'],
            ['enum' => FormFieldEnum::REQUEST_REASON_SIZE_CHANGE_FEMALE, 'display_name' => 'שינוי מידה'],
            ['enum' => FormFieldEnum::REQUEST_REASON_SOLDIER_ASSIGNMENT_FEMALE, 'display_name' => 'שיוך חיילי'],
            ['enum' => FormFieldEnum::REQUEST_REASON_EXEMPTION_FEMALE, 'display_name' => 'פטור'],
        ];
    }

    // EQUIPMENT-BOOKING-FEMALE
    private function equipmentFemaleBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::EQUIPMENT_FEMALE->getFormFieldName(),
                'field_display_name' => 'ציוד',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => null,
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            // Root field: a sibling of equipment_female, not its child.
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::REQUEST_REASON_FEMALE->getFormFieldName(),
                'field_display_name' => 'סיבת הבקשה',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק סיבת הבקשה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
        ];
    }

    /** EQUIPMENT_BOOKING_FEMALE category child fields: equipment_female -> clothing/footwear/related_products. */
    private function createEquipmentFemaleCategoryFields(int $bookingTypeId): void
    {
        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::EQUIPMENT_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_FEMALE->getFormFieldName(),
            childDisplayName: 'ביגוד',
            childValidationMessage: 'יש לספק סוג ביגוד',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::EQUIPMENT_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::FOOTWEAR_FEMALE->getFormFieldName(),
            childDisplayName: 'הנעלה',
            childValidationMessage: 'יש לספק סוג הנעלה',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::EQUIPMENT_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::RELATED_PRODUCTS_FEMALE->getFormFieldName(),
            childDisplayName: 'מוצרים נלווים',
            childValidationMessage: 'יש לספק מוצרים נלווים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );
    }

    /** EQUIPMENT_BOOKING_FEMALE promoted item child fields; sizes still live one level below as OptionFields. */
    private function createEquipmentFemalePromotedItemFields(int $bookingTypeId): void
    {
        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_FEMALE_SHIRT->getFormFieldName(),
            childDisplayName: 'חולצות נשים',
            childValidationMessage: 'יש לספק מידת חולצות נשים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_FEMALE_PANTS->getFormFieldName(),
            childDisplayName: 'מכנסי נשים',
            childValidationMessage: 'יש לספק מידת מכנסי נשים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        // Cotton uniform sits under clothing; its children and sizes are OptionFields.
        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::CLOTHING_FEMALE_COTTON_UNIFORM->getFormFieldName(),
            childDisplayName: 'מדי כותנה',
            childValidationMessage: 'יש לספק פרטי מדי כותנה',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::FOOTWEAR_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::FOOTWEAR_FEMALE_SHOES->getFormFieldName(),
            childDisplayName: 'נעלי נשים',
            childValidationMessage: 'יש לספק מידת נעלי נשים',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::FOOTWEAR_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::FOOTWEAR_FEMALE_VEGAN->getFormFieldName(),
            childDisplayName: 'נעלים בהתאמה אישית',
            childValidationMessage: 'יש לספק מידת נעלים טבעוניות',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );

        $this->createEquipmentCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::FOOTWEAR_FEMALE->getFormFieldName(),
            childFieldName: FormFieldEnum::FOOTWEAR_FEMALE_INFANTRY->getFormFieldName(),
            childDisplayName: 'חי"ר (בזכאות)',
            childValidationMessage: 'יש לספק מידת חי"ר (בזכאות)',
            payloadSectionId: PayloadSectionEnum::NONE->value,
        );
    }


    /** EQUIPMENT_BOOKING_FEMALE child quantity fields. */
    private function createEquipmentFemaleQuantityFields(int $bookingTypeId): void
    {
        $this->createQuantityChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CLOTHING_FEMALE->getFormFieldName(),
            quantityFieldName: FormFieldEnum::QUANTITY_CLOTHING_FEMALE->getFormFieldName(),
            payloadSectionId: PayloadSectionEnum::ITEMS->value,
        );

        $this->createQuantityChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::FOOTWEAR_FEMALE->getFormFieldName(),
            quantityFieldName: FormFieldEnum::QUANTITY_FOOTWEAR_FEMALE->getFormFieldName(),
            payloadSectionId: PayloadSectionEnum::ITEMS->value,
        );

        $this->createQuantityChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::RELATED_PRODUCTS_FEMALE->getFormFieldName(),
            quantityFieldName: FormFieldEnum::QUANTITY_RELATED_PRODUCTS_FEMALE->getFormFieldName(),
            payloadSectionId: PayloadSectionEnum::ITEMS->value,
        );
    }

    /** EQUIPMENT_BOOKING_FEMALE request reason leaf fields; same idea as the male version - no OptionField. */
    private function createEquipmentFemaleRequestReasonFields(int $bookingTypeId): void
    {
        // request_reason_female is a root field created earlier; only its leaves are created here.
        foreach ($this->equipmentFemaleRequestReasonLeafFields() as $leafField) {
            $this->createEquipmentCategoryChildFormField(
                bookingTypeId: $bookingTypeId,
                parentFieldName: FormFieldEnum::REQUEST_REASON_FEMALE->getFormFieldName(),
                childFieldName: $leafField['enum']->getFormFieldName(),
                childDisplayName: $leafField['display_name'],
                childValidationMessage: "יש לספק {$leafField['display_name']}",
                payloadSectionId: PayloadSectionEnum::SELECTS->value,
            );
        }
    }

    // Equipment helpers (male and female)
    /** Create one equipment category child field. */
    private function createEquipmentCategoryChildFormField(
        int $bookingTypeId,
        string $parentFieldName,
        string $childFieldName,
        string $childDisplayName,
        string $childValidationMessage,
        ?int $payloadSectionId = PayloadSectionEnum::NONE->value,
    ): void {
        $parentFormField = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->value)
            ->where('field_name', $parentFieldName)
            ->first();

        if (!$parentFormField) {
            return;
        }

        $field = $this->equipmentCategoryChildFormField(
            fieldName: $childFieldName,
            fieldDisplayName: $childDisplayName,
            parentFormFieldId: $parentFormField->id,
            validationMessage: $childValidationMessage,
            payloadSectionId: $payloadSectionId,
        );

        FormField::updateOrCreate(
            [
                'booking_type_id' => $bookingTypeId,
                'unit_id' => $field['unit_id'],
                'field_name' => $field['field_name'],
            ],
            [
                'field_display_name' => $field['field_display_name'],
                'field_type_id' => $field['field_type_id'],
                'form_field_id' => $field['form_field_id'] ?? null,
                'is_input' => $field['is_input'] ?? false,
                'field_validations' => $field['field_validations'] ?? null,
                'editable' => $field['editable'] ?? true,
                'is_required' => $field['is_required'] ?? true,
                'payload_section_id' => $field['payload_section_id'] ?? PayloadSectionEnum::NONE->value,
            ]
        );
    }



    /** Equipment category child form field. */
    private function equipmentCategoryChildFormField(
        string $fieldName,
        string $fieldDisplayName,
        int $parentFormFieldId,
        string $validationMessage,
        ?int $payloadSectionId = PayloadSectionEnum::NONE->value,
    ): array {
        return [
            'unit_id' => UnitEnum::H->value,
            'field_name' => $fieldName,
            'field_display_name' => $fieldDisplayName,
            'field_type_id' => FieldTypeEnum::SELECT->value,
            'form_field_id' => $parentFormFieldId,
            'is_input' => false,
            'field_validations' => json_encode([
                'required' => $validationMessage,
            ], JSON_UNESCAPED_UNICODE),
            'editable' => 0,
            'is_required' => 1,
            'payload_section_id' => $payloadSectionId,
        ];
    }

    /** Create one quantity child field for a parent form field. */
    private function createQuantityChildFormField(
        int $bookingTypeId,
        string $parentFieldName,
        string $quantityFieldName,
        ?int $payloadSectionId = PayloadSectionEnum::NONE->value,
    ): void {
        $parentFormField = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->value)
            ->where('field_name', $parentFieldName)
            ->first();

        if (!$parentFormField) {
            return;
        }

        $field = $this->quantityChildFormField(
            fieldName: $quantityFieldName,
            parentFormFieldId: $parentFormField->id,
            payloadSectionId: $payloadSectionId,
        );

        FormField::updateOrCreate(
            [
                'booking_type_id' => $bookingTypeId,
                'unit_id' => $field['unit_id'],
                'field_name' => $field['field_name'],
            ],
            [
                'field_display_name' => $field['field_display_name'],
                'field_type_id' => $field['field_type_id'],
                'form_field_id' => $field['form_field_id'],
                'is_input' => $field['is_input'],
                'field_validations' => $field['field_validations'],
                'editable' => $field['editable'],
                'is_required' => $field['is_required'],
                'payload_section_id' => $field['payload_section_id'] ?? PayloadSectionEnum::NONE->value,
            ]
        );
    }


    /** Quantity child form field. */
    private function quantityChildFormField(
        string $fieldName,
        int $parentFormFieldId,
        ?int $payloadSectionId = PayloadSectionEnum::NONE->value,
    ): array {
        return [
            'unit_id' => UnitEnum::H->value,
            'field_name' => $fieldName,
            'field_display_name' => 'כמות',
            'field_type_id' => FieldTypeEnum::NUMBER->value,
            'form_field_id' => $parentFormFieldId,
            'is_input' => true,
            'field_validations' => json_encode([
                'required' => 'יש לספק כמות',
                'integer' => 'ערך שדה אינו תקין',
                'min:1' => 'כמות חייבת להיות לפחות 1',
            ], JSON_UNESCAPED_UNICODE),
            'editable' => 1,
            'is_required' => 1,

            'payload_section_id' => $payloadSectionId,
        ];
    }

    // CAR-MAINTENANCE-BOOKING
    private function carMaintenanceBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_COMPANY_NAME->getFormFieldName(),
                'field_display_name' => 'חברת ליסינג',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק סוג חברת ליסינג',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_MODEL->getFormFieldName(),
                'field_display_name' => 'דגם',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק דגם',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_VEHICLE_NUMBER->getFormFieldName(),
                'field_display_name' => 'מספר רכב',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר רכב',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_CAR_CODE->getFormFieldName(),
                'field_display_name' => 'קוד רכב',
                'field_type_id' => FieldTypeEnum::NUMBER_ASTERISK->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק קוד רכב',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_FUEL_CODE->getFormFieldName(),
                'field_display_name' => 'קוד דלק',
                'field_type_id' => FieldTypeEnum::NUMBER_ASTERISK->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק קוד דלק',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_CURRENT_KILOMETER->getFormFieldName(),
                'field_display_name' => 'קילומטר נוכחי',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק קילומטר נוכחי',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_CORE_PROBLEM->getFormFieldName(),
                'field_display_name' => 'מהות התקלה',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מהות תקלה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_GARAGE_NAME->getFormFieldName(),
                'field_display_name' => 'שם המוסך',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם המוסך',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_GARAGE_ADDRESS->getFormFieldName(),
                'field_display_name' => 'כתובת המוסך',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק כתובת המוסך',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_DATE_SET->getFormFieldName(),
                'field_display_name' => 'תאריך שנקבע עם מוסך מטפל',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך שנקבע עם מוסך מטפל',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CAR_MAINTANENCE_COMMENT->getFormFieldName(),
                'field_display_name' => 'הערות',
                'field_type_id' => FieldTypeEnum::TEXT_AREA->value,
                'field_validations' => json_encode([
                    'nullable' => true,
                    'string' => 'ערך השדה אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 0,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
        ];
    }

    // BARBER-BOOKING
    private function barberBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::BARBER_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך',
                    'date' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::BARBER_TIME->getFormFieldName(),
                'field_display_name' => 'שעה',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעה',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
        ];
    }

    // CONSTRUCTION-BOOKING
    /** CONSTRUCTION_BOOKING root-level fields; the fault type select's category children are created below. */
    private function constructionBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CONSTRUCTION_BUILDING_NUMBER->getFormFieldName(),
                'field_display_name' => 'מספר מבנה',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר מבנה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CONSTRUCTION_FAULT_TYPE->getFormFieldName(),
                'field_display_name' => 'סוג תקלה',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => null,
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CONSTRUCTION_NOTES->getFormFieldName(),
                'field_display_name' => 'הערות',
                'field_type_id' => FieldTypeEnum::TEXT_AREA->value,
                'field_validations' => json_encode([
                    'nullable' => true,
                    'string' => 'ערך שאינו טקסט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 0,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CONSTRUCTION_IMAGES->getFormFieldName(),
                'field_display_name' => 'תמונות',
                'field_type_id' => FieldTypeEnum::IMAGE->value,
                'field_validations' => json_encode([
                    'nullable' => true,
                    'array' => 'יש לצרף תמונות בפורמט תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 0,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
        ];
    }

    /** CONSTRUCTION_BOOKING fault category child fields; their leaf OptionFields live in OptionFieldSeeder. */
    private function createConstructionFaultCategoryFields(int $bookingTypeId): void
    {
        $this->createConstructionCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CONSTRUCTION_FAULT_TYPE->getFormFieldName(),
            childFieldName: FormFieldEnum::CONSTRUCTION_ELECTRICITY->getFormFieldName(),
            childDisplayName: 'חשמל',
            childValidationMessage: 'יש לספק סוג תקלת חשמל',
        );

        $this->createConstructionCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CONSTRUCTION_FAULT_TYPE->getFormFieldName(),
            childFieldName: FormFieldEnum::CONSTRUCTION_STRUCTURE->getFormFieldName(),
            childDisplayName: 'בינוי',
            childValidationMessage: 'יש לספק סוג תקלת בינוי',
        );

        $this->createConstructionCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CONSTRUCTION_FAULT_TYPE->getFormFieldName(),
            childFieldName: FormFieldEnum::CONSTRUCTION_AC->getFormFieldName(),
            childDisplayName: 'מיזוג',
            childValidationMessage: 'יש לספק סוג תקלת מיזוג',
        );

        $this->createConstructionCategoryChildFormField(
            bookingTypeId: $bookingTypeId,
            parentFieldName: FormFieldEnum::CONSTRUCTION_FAULT_TYPE->getFormFieldName(),
            childFieldName: FormFieldEnum::CONSTRUCTION_PLUMBING->getFormFieldName(),
            childDisplayName: 'אינסטלציה',
            childValidationMessage: 'יש לספק סוג תקלת אינסטלציה',
        );
    }

    /** Create one construction fault category child field. */
    private function createConstructionCategoryChildFormField(
        int $bookingTypeId,
        string $parentFieldName,
        string $childFieldName,
        string $childDisplayName,
        string $childValidationMessage,
    ): void {
        $parentFormField = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->value)
            ->where('field_name', $parentFieldName)
            ->first();

        if (!$parentFormField) {
            return;
        }

        FormField::updateOrCreate(
            [
                'booking_type_id' => $bookingTypeId,
                'unit_id' => UnitEnum::H->value,
                'field_name' => $childFieldName,
            ],
            [
                'field_display_name' => $childDisplayName,
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'form_field_id' => $parentFormField->id,
                'is_input' => false,
                'field_validations' => json_encode([
                    'required' => $childValidationMessage,
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ]
        );
    }

    // TRANSPORTATION-BOOKING
    /** TRANSPORTATION_BOOKING form fields; the 4 selects are flat root fields, leaves live in OptionFieldSeeder. */
    private function transportationBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_ORDER_TYPE->getFormFieldName(),
                'field_display_name' => 'סוג הזמנה',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק סוג הזמנה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_CARGO->getFormFieldName(),
                'field_display_name' => 'מטען',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק סוג מטען',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_VEHICLE_TYPE->getFormFieldName(),
                'field_display_name' => 'סוג רכב',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק סוג רכב',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_ASSEMBLY_POINT->getFormFieldName(),
                'field_display_name' => 'מקום התייצבות',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מקום התייצבות',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_START_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך התחלה',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך התחלה',
                    'date' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_START_TIME->getFormFieldName(),
                'field_display_name' => 'שעת התחלה',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעת התחלה',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_END_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך סיום',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך סיום',
                    'date' => 'פורמט אינו תקין',
                    'after_or_equal:start_date' => 'תאריך סיום חייב להיות אחרי תאריך התחלה',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_END_TIME->getFormFieldName(),
                'field_display_name' => 'שעת סיום',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעת סיום',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_CONTACT_NAME->getFormFieldName(),
                'field_display_name' => 'שם איש קשר',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם איש קשר',
                    'string' => 'פורמט אינו תקין',
                    'regex:/^[a-zA-Z0-9א-ת\s]+$/' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_CONTACT_PHONE->getFormFieldName(),
                'field_display_name' => 'טלפון איש קשר',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר טלפון איש קשר',
                    'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/' => 'מספר טלפון אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_ADD_CONTACT_NAME->getFormFieldName(),
                'field_display_name' => 'שם איש קשר נוסף',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם איש קשר נוסף',
                    'string' => 'פורמט אינו תקין',
                    'regex:/^[a-zA-Z0-9א-ת\s]+$/' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_ADD_CONTACT_PHONE->getFormFieldName(),
                'field_display_name' => 'טלפון איש קשר נוסף',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר טלפון איש קשר נוסף',
                    'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/' => 'מספר טלפון אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'is_input' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::TRANSPORTATION_NOTES->getFormFieldName(),
                'field_display_name' => 'הערות',
                'field_type_id' => FieldTypeEnum::MULTI_SELECT->value,
                'field_validations' => json_encode([
                    'nullable' => true,
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 0,
                'is_input' => 0,
                'payload_section_id' => PayloadSectionEnum::NONE->value,
            ],
        ];
    }

    // FOOD-BOOKING
    private function foodBookingFields(): array
    {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::EVENT_NAME->getFormFieldName(),
                'field_display_name' => 'שם אירוע',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם אירוע',
                    'string' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::EVENT_TYPE->getFormFieldName(),
                'field_display_name' => 'סוג אירוע',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק סוג אירוע',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::LOCATION->getFormFieldName(),
                'field_display_name' => 'מיקום',
                'field_type_id' => FieldTypeEnum::SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מיקום',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::SUPPLY_DATE->getFormFieldName(),
                'field_display_name' => 'תאריך אספקה',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק תאריך התחלה',
                    'date' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::SUPPLY_TIME->getFormFieldName(),
                'field_display_name' => 'שעת אספקה',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שעת אספקה',
                    'date_format:H:i' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::GUESTS_NUM->getFormFieldName(),
                'field_display_name' => 'כמות מוזמנים',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק כמות מוזמנים',
                    'numeric' => 'יש לספק מספר',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::ALLERGY_BTN->getFormFieldName(),
                'field_display_name' => 'רגישות',
                'field_type_id' => FieldTypeEnum::RADIO->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מידע על רגישויות',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CONTACT_NAME->getFormFieldName(),
                'field_display_name' => 'שם איש קשר',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק שם איש קשר',
                    'string' => 'פורמט אינו תקין',
                    'regex:/^[a-zA-Z0-9א-ת\s]+$/' => 'פורמט אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => FormFieldEnum::CONTACT_PHONE->getFormFieldName(),
                'field_display_name' => 'טלפון איש קשר',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'field_validations' => json_encode([
                    'required' => 'יש לספק מספר טלפון איש קשר',
                    'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/' => 'מספר טלפון לא תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
        ];
    }

    // WORK-SPACE-BOOKING
    private function lectureRoomBookingFields(): array
    {
        return $this->workSpaceBookingFields(
            dateFieldName: FormFieldEnum::LECTURE_ROOM_DATE->getFormFieldName(),
            timeFieldName: FormFieldEnum::LECTURE_ROOM_TIME->getFormFieldName(),
            userAmountFieldName: FormFieldEnum::LECTURE_ROOM_USER_AMOUNT->getFormFieldName(),
            eventPurposeFieldName: FormFieldEnum::LECTURE_ROOM_EVENT_PURPOSE->getFormFieldName(),
        );
    }

    private function smallCommandRoomBookingFields(): array
    {
        return $this->workSpaceBookingFields(
            dateFieldName: FormFieldEnum::SMALL_COMMAND_ROOM_DATE->getFormFieldName(),
            timeFieldName: FormFieldEnum::SMALL_COMMAND_ROOM_TIME->getFormFieldName(),
            userAmountFieldName: FormFieldEnum::SMALL_COMMAND_ROOM_USER_AMOUNT->getFormFieldName(),
            eventPurposeFieldName: FormFieldEnum::SMALL_COMMAND_ROOM_EVENT_PURPOSE->getFormFieldName(),
        );
    }

    private function techHamamaFirstBookingFields(): array
    {
        return $this->workSpaceBookingFields(
            dateFieldName: FormFieldEnum::TECH_HAMAMA_FIRST_DATE->getFormFieldName(),
            timeFieldName: FormFieldEnum::TECH_HAMAMA_FIRST_TIME->getFormFieldName(),
            userAmountFieldName: FormFieldEnum::TECH_HAMAMA_FIRST_USER_AMOUNT->getFormFieldName(),
            eventPurposeFieldName: FormFieldEnum::TECH_HAMAMA_FIRST_EVENT_PURPOSE->getFormFieldName(),
        );
    }

    private function techHamamaSecondBookingFields(): array
    {
        return $this->workSpaceBookingFields(
            dateFieldName: FormFieldEnum::TECH_HAMAMA_SECOND_DATE->getFormFieldName(),
            timeFieldName: FormFieldEnum::TECH_HAMAMA_SECOND_TIME->getFormFieldName(),
            userAmountFieldName: FormFieldEnum::TECH_HAMAMA_SECOND_USER_AMOUNT->getFormFieldName(),
            eventPurposeFieldName: FormFieldEnum::TECH_HAMAMA_SECOND_EVENT_PURPOSE->getFormFieldName(),
        );
    }

    private function workSpaceBookingFields(
        string $dateFieldName,
        string $timeFieldName,
        string $userAmountFieldName,
        string $eventPurposeFieldName,
    ): array {
        return [
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => $dateFieldName,
                'field_display_name' => 'תאריך',
                'field_type_id' => FieldTypeEnum::DATE->value,
                'field_validations' => json_encode([
                    'required' => 'יש לבחור תאריך',
                    'date' => 'פורמט תאריך אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => $timeFieldName,
                'field_display_name' => 'שעת התחלה',
                'field_type_id' => FieldTypeEnum::MULTI_SELECT->value,
                'field_validations' => json_encode([
                    'required' => 'יש לבחור שעת התחלה',
                    'date_format:H:i' => 'פורמט שעה אינו תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 1,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => $userAmountFieldName,
                'field_display_name' => 'כמות משתמשים',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'field_validations' => json_encode([
                    'required' => 'יש להזין כמות משתמשים',
                    'numeric' => 'יש להזין מספר תקין',
                    'min:1' => 'כמות משתמשים חייבת להיות לפחות 1',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
            [
                'unit_id' => UnitEnum::H->value,
                'field_name' => $eventPurposeFieldName,
                'field_display_name' => 'מטרת האירוע',
                'field_type_id' => FieldTypeEnum::TEXT->value,
                'field_validations' => json_encode([
                    'required' => 'יש להזין מטרת אירוע',
                    'string' => 'פורמט אינו תקין',
                    'max:255' => 'ניתן להזין עד 255 תווים',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
            ],
        ];
    }

    // EQUIPMENT_BOOKING_MALE | FEMALE - ATTACHED FILE FIELDS
    /** Create the required exemption-reason FILE child of an equipment FormField (needed for custom shoes). */
    private function createEquipmentAttachedFileField(
        int $bookingTypeId,
        string $parentFieldName,
        string $fileFieldName,
        string $requiredMessage,
    ): void {
        $parentFormField = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->value)
            ->where('field_name', $parentFieldName)
            ->first();

        if (!$parentFormField) {
            return;
        }

        FormField::updateOrCreate(
            [
                'booking_type_id' => $bookingTypeId,
                'unit_id' => UnitEnum::H->value,
                'field_name' => $fileFieldName,
            ],
            [
                'field_display_name' => 'העלאת קובץ',
                'field_type_id' => FieldTypeEnum::FILE->value,
                'form_field_id' => $parentFormField->id,
                'is_input' => true,
                'field_validations' => json_encode([
                    'required' => $requiredMessage,
                    'array' => 'יש לצרף קבצים בפורמט תקין',
                ], JSON_UNESCAPED_UNICODE),
                'editable' => 0,
                'is_required' => 1,
                'payload_section_id' => PayloadSectionEnum::INPUTS->value,
            ]
        );
    }

}

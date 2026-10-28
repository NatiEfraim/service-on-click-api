<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\SystemReferenceEnum;
use App\Enums\UnitEnum;
use App\Models\SystemReference;
use App\Models\SystemReferenceHasFormField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class SystemReferencesSeeder extends Seeder
{
    /** Run the database seeds. */
    public function run(): void
    {



        try {
            DB::beginTransaction();
            foreach ($this->systemReferencesData() as $bookingTypeId => $references) {
                $this->createSystemReferences(
                    bookingTypeId: (int) $bookingTypeId,
                    references: $references,
                );
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

        }


    }



    private function systemReferencesData(): array
    {
        return [

            BookingTypeEnum::CAR_BOOKING->getBookingTypeId() => $this->carBookingReferences(),

            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId() => $this->inUnitFoodBookingReferences(),

            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId() => $this->outUnitFoodBookingReferences(),

            BookingTypeEnum::EQUIPMENT_BOOKING_MALE->getBookingTypeId() => $this->equipmentBookingMaleReferences(),

            BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE->getBookingTypeId() => $this->equipmentBookingFemaleReferences(),

            BookingTypeEnum::BARBER_BOOKING->getBookingTypeId() => $this->barberBookingReferences(),
            
            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId() => $this->carMaintenanceBookingReferences(),
            
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId() => $this->transportationBookingReferences(),

            BookingTypeEnum::CONSTRUCTION_BOOKING->getBookingTypeId() => $this->constructionBookingReferences(),
            
        ];
    }

    private function createSystemReferences(int $bookingTypeId, array $references): void
    {
        foreach ($references as $data) {
            $systemReference = SystemReference::updateOrCreate(
                [
                    'booking_type_id' => $bookingTypeId,
                    'title' => $data['title'],
                ],
                [
                    'unit_id' => $data['unit_id'],
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'field_type_id' => $data['field_type_id'],
                    'value' => $data['value'],
                    'limit' => (bool) $data['limit'],
                ]
            );

            SystemReferenceHasFormField::query()
                ->where('system_reference_id', $systemReference->id)
                ->delete();

            if (empty($data['form_fields'])) {
                continue;
            }

            foreach ($data['form_fields'] as $formFieldId) {
                SystemReferenceHasFormField::create([
                    'system_reference_id' => $systemReference->id,
                    'form_field_id' => $formFieldId,
                ]);
            }
        }
    }


    // ---------------------------- CAR-BOOKING ---------------------------------------------- //

    private function carBookingReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::CAR_TOTAL_CAR_STOCK->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'סה"כ רכבים',
                'description' => 'יש לציין כמה רכבים סה"כ ניתנים להזמנה, על מנת שהמערכת תוכל להתריע כאשר שוריינו יותר מידי רכבים.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode(['stock_cars' => 15], JSON_UNESCAPED_UNICODE),
                'limit' => true,
                'form_fields' => null,
            ],
            [
                'name' => SystemReferenceEnum::CAR_SERVICE_HOURS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'שעות פעילות',
                'description' => 'יש לציין שעות פעילות של שירות',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'value' => json_encode([
                    'start_time' => '08:00',
                    'end_time' => '17:00',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::CAR_BOOKING_START_TIME->getFormFieldId(),
                    FormFieldEnum::CAR_BOOKING_END_TIME->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::CAR_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode(['contact_phone' => '03-7371111'], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],
            [
                'name' => SystemReferenceEnum::CAR_RESTRICTIONS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'הגבלת זמני הזמנות',
                'description' => 'יש לציין ממתי יוכלו להתקבל הזמנות. כתבו את כמות השעות המינימלית שלקוח יכול להזמין רכב.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode(['bookings_limit_time' => 48], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::CAR_BOOKING_START_DATE->getFormFieldId(),
                    FormFieldEnum::CAR_BOOKING_END_DATE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::CAR_RESTRICTION_DATES->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'תאריכים חסומים להזמנת רכב',
                'description' => 'יש לציין תאריכים בהם לא ניתן לבצע הזמנת רכב.',
                'field_type_id' => FieldTypeEnum::MULTI_DATE->value,
                'value' => json_encode([
                    'dates' => [],
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::CAR_BOOKING_START_DATE->getFormFieldId(),
                    FormFieldEnum::CAR_BOOKING_END_DATE->getFormFieldId(),
                ],
            ],
        ];
    }


    //-------------------------------------------------- IN_UNIT_FOOD_BOOKING system references ------------------------------- //
    private function inUnitFoodBookingReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::IN_UNIT_FOOD_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],
            [
                'name' => SystemReferenceEnum::IN_UNIT_FOOD_RESTRICTIONS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'הגבלת זמני הזמנות',
                'description' => 'יש לציין ממתי יוכלו להתקבל הזמנות. כתבו את כמות השעות המינימלית שלקוח יכול להזמין מזון.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'bookings_limit_time' => 48,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::IN_UNIT_RESTRICTION_DATES->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'תאריכים חסומים להזמנת מזון',
                'description' => 'יש לציין תאריכים בהם לא ניתן לבצע הזמנת מזון.',
                'field_type_id' => FieldTypeEnum::MULTI_DATE->value,
                'value' => json_encode([
                    'dates' => [],
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId(),
                    FormFieldEnum::IN_UNIT_SUPPLY_TIME->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::IN_UNIT_FOOD_SERVICE_HOURS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'שעות פעילות',
                'description' => 'יש לציין שעות פעילות של שירות',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'value' => json_encode([
                    'start_time' => '08:00',
                    'end_time' => '17:00',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::IN_UNIT_SUPPLY_TIME->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::IN_UNIT_FOOD_PEOPLE_AMOUNT->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'כמות אנשים',
                'description' => 'יש להגדיר באילו כפולות ניתן להזמין כמות אנשים.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'multiple_of' => 10,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::IN_UNIT_AMOUNT_PEOPLE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::IN_UNIT_FOOD_COUNT_BUSINESS_DAYS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'ספירת ימי עסקים',
                'description' => 'יש לציין האם הגבלת השעות (בין ההזמנה לבין תאריך האספקה) תיספר לפי ימי עסקים בלבד או לפי כל ימות השבוע.',
                'field_type_id' => FieldTypeEnum::CHECKBOX->value,
                'value' => json_encode([
                    'count_businsday' => true,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::IN_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'ימי ביטול מראש',
                'description' => 'מספר ימי העסקים המינימלי הנדרש לביטול הזמנה לפני תאריך האספקה שנבחר.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'cancellation_advance_days' => 5,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId(),
                ],
            ],
            $this->foodMaxAdvanceDaysReference(
                name: SystemReferenceEnum::IN_UNIT_FOOD_MAX_ADVANCE_DAYS->getSystemReferenceName(),
                supplyDateFormFieldId: FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId(),
            ),
        ];
    }



    // --------------------------------------- OUT_UNIT_FOOD_BOOKING system references ------------------------------------------ //

    private function outUnitFoodBookingReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::OUT_UNIT_FOOD_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],
            [
                'name' => SystemReferenceEnum::OUT_UNIT_FOOD_RESTRICTIONS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'הגבלת זמני הזמנות',
                'description' => 'יש לציין ממתי יוכלו להתקבל הזמנות. כתבו את כמות השעות המינימלית שלקוח יכול להזמין מזון.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'bookings_limit_time' => 48,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::OUT_UNIT_RESTRICTION_DATES->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'תאריכים חסומים להזמנת מזון',
                'description' => 'יש לציין תאריכים בהם לא ניתן לבצע הזמנת מזון.',
                'field_type_id' => FieldTypeEnum::MULTI_DATE->value,
                'value' => json_encode([
                    'dates' => [],
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId(),
                    FormFieldEnum::OUT_UNIT_SUPPLY_TIME->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::OUT_UNIT_FOOD_SERVICE_HOURS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'שעות פעילות',
                'description' => 'יש לציין שעות פעילות של שירות',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'value' => json_encode([
                    'start_time' => '08:00',
                    'end_time' => '17:00',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::OUT_UNIT_SUPPLY_TIME->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'ספירת ימי עסקים',
                'description' => 'יש לציין האם הגבלת השעות (בין ההזמנה לבין תאריך האספקה) תיספר לפי ימי עסקים בלבד או לפי כל ימות השבוע.',
                'field_type_id' => FieldTypeEnum::CHECKBOX->value,
                'value' => json_encode([
                    'count_businsday' => true,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::OUT_UNIT_FOOD_MINIMUM_ORDER_AMOUNT->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מינימום הזמנה - כמות אנשים',
                'description' => 'יש לרשום מספר מינימלי של הזמנה עבור כמות האנשים.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'min' => 25,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::OUT_UNIT_AMOUNT_PEOPLE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::OUT_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'ימי ביטול מראש',
                'description' => 'מספר ימי העסקים המינימלי הנדרש לביטול הזמנה לפני תאריך האספקה שנבחר.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'cancellation_advance_days' => 5,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId(),
                ],
            ],
            $this->foodMaxAdvanceDaysReference(
                name: SystemReferenceEnum::OUT_UNIT_FOOD_MAX_ADVANCE_DAYS->getSystemReferenceName(),
                supplyDateFormFieldId: FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId(),
            ),
        ];
    }

    /** Build the food max-advance-days reference (business days when that card is on). @param string $name, ?int $supplyDateFormFieldId, int $maxAdvanceDays @return array */
    private function foodMaxAdvanceDaysReference(string $name, ?int $supplyDateFormFieldId, int $maxAdvanceDays = 30): array
    {
        return [
            'name' => $name,
            'unit_id' => UnitEnum::H->value,
            'title' => 'מקסימום ימים להזמנה מראש',
            'description' => 'יש לציין עד כמה ימים קדימה מיום ההזמנה יהיה ניתן להזמין. כאשר "ספירת ימי עסקים" מסומנת - נספרים ימי עסקים בלבד.',
            'field_type_id' => FieldTypeEnum::NUMBER->value,
            'value' => json_encode([
                'max_advance_days' => $maxAdvanceDays,
            ], JSON_UNESCAPED_UNICODE),
            'limit' => true,
            'form_fields' => array_filter([
                $supplyDateFormFieldId,
            ]),
        ];
    }



    //---------------------------- EQUIPMENT-BOOKING-Male  -------------------------- //

    private function equipmentBookingMaleReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::EQUIPMENT_MALE_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],

            $this->quantityLimitReference(
                name: SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_CLOTHING->getSystemReferenceName(),
                title: 'הגבלת כמות - ביגוד',
                description: 'הגדרת כמות מקסימלית עבור שדה ביגוד.',
                formFieldId: FormFieldEnum::QUANTITY_CLOTHING_MALE->getFormFieldId(),
                max: 2,
            ),

            $this->quantityLimitReference(
                name: SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_FOOTWEAR->getSystemReferenceName(),
                title: 'הגבלת כמות - הנעלה',
                description: 'הגדרת כמות מקסימלית עבור שדה הנעלה.',
                formFieldId: FormFieldEnum::QUANTITY_FOOTWEAR_MALE->getFormFieldId(),
                max: 2,
            ),

            $this->quantityLimitReference(
                name: SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_RELATED_PRODUCTS->getSystemReferenceName(),
                title: 'הגבלת כמות - מוצרים נלווים',
                description: 'הגדרת כמות מקסימלית עבור שדה מוצרים נלווים.',
                formFieldId: FormFieldEnum::QUANTITY_RELATED_PRODUCTS_MALE->getFormFieldId(),
                max: 2,
            ),
        ];
    }

//--------------------------------- Equipment-Booking-Female --------------------------//

    private function equipmentBookingFemaleReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::EQUIPMENT_FEMALE_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],

            $this->quantityLimitReference(
                name: SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_CLOTHING->getSystemReferenceName(),
                title: 'הגבלת כמות - ביגוד',
                description: 'הגדרת כמות מקסימלית עבור שדה ביגוד.',
                formFieldId: FormFieldEnum::QUANTITY_CLOTHING_FEMALE->getFormFieldId(),
                max: 2,
            ),

            $this->quantityLimitReference(
                name: SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_FOOTWEAR->getSystemReferenceName(),
                title: 'הגבלת כמות - הנעלה',
                description: 'הגדרת כמות מקסימלית עבור שדה הנעלה.',
                formFieldId: FormFieldEnum::QUANTITY_FOOTWEAR_FEMALE->getFormFieldId(),
                max: 2,
            ),

            $this->quantityLimitReference(
                name: SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_RELATED_PRODUCTS->getSystemReferenceName(),
                title: 'הגבלת כמות - מוצרים נלווים',
                description: 'הגדרת כמות מקסימלית עבור שדה מוצרים נלווים.',
                formFieldId: FormFieldEnum::QUANTITY_RELATED_PRODUCTS_FEMALE->getFormFieldId(),
                max: 2,
            ),
        ];
    }


    private function quantityLimitReference(
        string $name,
        string $title,
        string $description,
        ?int $formFieldId,
        int $max = 2,
    ): array {
        return [
            'name' => $name,
            'unit_id' => UnitEnum::H->value,
            'title' => $title,
            'description' => $description,
            'field_type_id' => FieldTypeEnum::NUMBER->value,
            'value' => json_encode([
                'max' => $max,
            ], JSON_UNESCAPED_UNICODE),
            'limit' => true,
            'form_fields' => array_filter([
                $formFieldId,
            ]),
        ];
    }

    //---------------------------- BARBER-BOOKING ------------------------------------------------ //
    private function barberBookingReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::BARBER_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],

            [
                'name' => SystemReferenceEnum::BARBER_SERVICE_HOURS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'שעות פעילות',
                'description' => 'יש לציין שעות פעילות של שירות המספרה',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'value' => json_encode([
                    'start_time' => '09:00',
                    'end_time' => '14:00',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::BARBER_TIME->getFormFieldId(),
                ],
            ],

            [
                'name' => SystemReferenceEnum::BARBER_BREAK_HOURS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'הפסקת צהרים',
                'description' => 'יש לציין שעת הפסקת צהרים',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'value' => json_encode([
                    'start_time' => '12:00',
                    'end_time' => '13:00',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::BARBER_TIME->getFormFieldId(),
                ],
            ],

            [
                'name' => SystemReferenceEnum::BARBER_HAIRCUT_TIME->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'זמן תספורת',
                'description' => 'יש לציין כמה זמן תספורת כל הזמנה.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'haircut_time' => 15,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => true,
                'form_fields' => [
                    FormFieldEnum::BARBER_TIME->getFormFieldId(),
                ],
            ],

            [
                'name' => SystemReferenceEnum::BARBER_CLEAN_TIME->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'זמן סידור אחרי תספורת',
                'description' => 'יש לציין כמה זמן נדרש לסידור וניקוי אחרי כל תספורת.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'clean_time' => 15,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => true,
                'form_fields' => [
                    FormFieldEnum::BARBER_TIME->getFormFieldId(),
                ],
            ],

            [
                'name' => SystemReferenceEnum::BARBER_ADVANCE_TIME->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'זמן מינימלי להזמנה מראש',
                'description' => 'יש לציין כמה שעות מראש ניתן להזמין תור.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'bookings_limit_time' => 24,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::BARBER_DATE->getFormFieldId(),
                ],
            ],

            [
                'name' => SystemReferenceEnum::BARBER_RESTRICTION_DATES->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'תאריכים חסומים להזמנת תור',
                'description' => 'יש לציין תאריכים בהם לא ניתן לבצע הזמנת תור.',
                'field_type_id' => FieldTypeEnum::MULTI_DATE->value,
                'value' => json_encode([
                    'dates' => [],
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::BARBER_DATE->getFormFieldId(),
                ],
            ],
        ];
    }

    //---------------------------- TRANSPORTATION-BOOKING ------------------------------------------------ //

    private function transportationBookingReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::TRANSPORTATION_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],
            [
                'name' => SystemReferenceEnum::TRANSPORTATION_SERVICE_HOURS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'שעות פעילות',
                'description' => 'יש להזין שעות פעילות של השירות שבינהן ניתן להזמין הובלה.',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'value' => json_encode([
                    'start_time' => '08:00',
                    'end_time' => '17:00',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::TRANSPORTATION_START_TIME->getFormFieldId(),
                    FormFieldEnum::TRANSPORTATION_END_TIME->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::TRANSPORTATION_MINIMUM_ADVANCE_DAYS->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מינימום ימי הזמנה מראש',
                'description' => 'מספר הימים הקלנדריים המינימלי הנדרש בין מועד ההזמנה לבין תאריך ההתחלה שנבחר.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'minimum_advance_days' => 14,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => true,
                'form_fields' => [
                    FormFieldEnum::TRANSPORTATION_START_DATE->getFormFieldId(),
                ],
            ],
            [
                'name' => SystemReferenceEnum::TRANSPORTATION_RESTRICTION_DATES->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'תאריכים חסומים להזמנת הובלות',
                'description' => 'יש להזין תאריכים אליהם לא ניתן להזמין הובלה.',
                'field_type_id' => FieldTypeEnum::MULTI_DATE->value,
                'value' => json_encode([
                    'dates' => [],
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::TRANSPORTATION_START_DATE->getFormFieldId(),
                    FormFieldEnum::TRANSPORTATION_END_DATE->getFormFieldId(),
                ],
            ],
        ];
    }

    //---------------------------- CONSTRUCTION-BOOKING ------------------------------------------------ //

    private function constructionBookingReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::CONSTRUCTION_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],
        ];
    }

    //---------------------------- CAR-MAINTENANCE-BOOKING ------------------------------------------------ //

    private function carMaintenanceBookingReferences(): array
    {
        return [
            [
                'name' => SystemReferenceEnum::CAR_MAINTENANCE_CONTACT_PHONE->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],
            [
                'name' => SystemReferenceEnum::CAR_MAINTENANCE_DRIVERS_AMOUNT->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'כמות נהגים',
                'description' => 'הגדרת כמות הנהגים הזמינים עבור טיפול רכב.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'drivers_amount' => 10,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => true,
                'form_fields' => null,
            ],
            [
                'name' => SystemReferenceEnum::CAR_MAINTENANCE_RESTRICTION_DATES->getSystemReferenceName(),
                'unit_id' => UnitEnum::H->value,
                'title' => 'תאריכים חסומים לתיאום שינוע רכב',
                'description' => 'יש להזין תאריכים אליהם לא ניתן לתאם שינוע רכב לטיפול.',
                'field_type_id' => FieldTypeEnum::MULTI_DATE->value,
                'value' => json_encode([
                    'dates' => [],
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => [
                    FormFieldEnum::CAR_MAINTANENCE_DATE_SET->getFormFieldId(),
                ],
            ],
        ];
    }

    //------------------------------WORK-SPACE-BOOKING ------------------------------------------------ //

    private function lectureRoomBookingReferences(): array
    {
        return $this->workSpaceBookingReferences(
            nameSuffix: 'lecture_room_booking',
            dateFormFieldId: FormFieldEnum::LECTURE_ROOM_DATE->getFormFieldId(),
            timeFormFieldId: FormFieldEnum::LECTURE_ROOM_TIME->getFormFieldId(),
        );
    }

    private function smallCommandRoomBookingReferences(): array
    {
        return $this->workSpaceBookingReferences(
            nameSuffix: 'small_command_room_booking',
            dateFormFieldId: FormFieldEnum::SMALL_COMMAND_ROOM_DATE->getFormFieldId(),
            timeFormFieldId: FormFieldEnum::SMALL_COMMAND_ROOM_TIME->getFormFieldId(),
        );
    }

    private function techHamamaFirstBookingReferences(): array
    {
        return $this->workSpaceBookingReferences(
            nameSuffix: 'tech_hamama_first_booking',
            dateFormFieldId: FormFieldEnum::TECH_HAMAMA_FIRST_DATE->getFormFieldId(),
            timeFormFieldId: FormFieldEnum::TECH_HAMAMA_FIRST_TIME->getFormFieldId(),
        );
    }

    private function techHamamaSecondBookingReferences(): array
    {
        return $this->workSpaceBookingReferences(
            nameSuffix: 'tech_hamama_second_booking',
            dateFormFieldId: FormFieldEnum::TECH_HAMAMA_SECOND_DATE->getFormFieldId(),
            timeFormFieldId: FormFieldEnum::TECH_HAMAMA_SECOND_TIME->getFormFieldId(),
        );
    }

    private function workSpaceBookingReferences(
        string $nameSuffix,
        ?int $dateFormFieldId,
        ?int $timeFormFieldId,
    ): array {
        return [
            [
                'name' => "contact_phone_{$nameSuffix}",
                'unit_id' => UnitEnum::H->value,
                'title' => 'מספר טלפון ליצירת קשר',
                'description' => 'מספר זה יופיע ללקוח בעמוד ההזמנה כך שיוכל ליצור קשר לבדיקת פרטים.',
                'field_type_id' => FieldTypeEnum::TEL->value,
                'value' => json_encode([
                    'contact_phone' => '03-7371111',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => null,
            ],
            [
                'name' => "service_hours_{$nameSuffix}",
                'unit_id' => UnitEnum::H->value,
                'title' => 'שעות פעילות',
                'description' => 'יש לציין שעות פעילות של שירות',
                'field_type_id' => FieldTypeEnum::TIME->value,
                'value' => json_encode([
                    'start_time' => '08:00',
                    'end_time' => '17:00',
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => array_filter([
                    $timeFormFieldId,
                ]),
            ],
            [
                'name' => "restrictions_{$nameSuffix}",
                'unit_id' => UnitEnum::H->value,
                'title' => 'הגבלת זמני הזמנות',
                'description' => 'יש לציין ממתי יוכלו להתקבל הזמנות. כתבו את כמות השעות המינימלית שלקוח יכול להזמין חדר.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'bookings_limit_time' => 24,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => false,
                'form_fields' => array_filter([
                    $dateFormFieldId,
                ]),
            ],
            [
                'name' => "duration_time_{$nameSuffix}",
                'unit_id' => UnitEnum::H->value,
                'title' => 'זמן שהייה במתחם',
                'description' => 'יש לציין זמן שהייה במתחם.',
                'field_type_id' => FieldTypeEnum::NUMBER->value,
                'value' => json_encode([
                    'duration_time' => 1,
                ], JSON_UNESCAPED_UNICODE),
                'limit' => true,
                'form_fields' => array_filter([
                    $timeFormFieldId,
                ]),
            ],
        ];
    }

 

}

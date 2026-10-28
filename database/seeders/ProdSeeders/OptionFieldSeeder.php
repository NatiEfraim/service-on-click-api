<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\GenderEnum;
use App\Enums\PayloadSectionEnum;
use App\Models\OptionField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class OptionFieldSeeder extends Seeder
{
    /** Run the database seeds. */
    public function run(): void
    {
        //
        try {
            DB::beginTransaction();

            foreach ($this->optionFieldsByBookingType() as $bookingTypeId => $formFields) {
                foreach ($formFields as $formFieldId => $optionFields) {
                    foreach ($optionFields as $optionField) {
                        if ($this->shouldCreateOptionFieldRecursive($bookingTypeId)) {
                            $this->createOptionFieldRecursive(formFieldId: $formFieldId, optionField: $optionField);

                            continue;
                        }

                        $this->createOptionField(formFieldId: $formFieldId, optionField: $optionField);
                    }
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            Log::error('OptionFieldSeeder failed. message:' . $e->getMessage());
            DB::rollBack();
        }
    }

    private function optionFieldsByBookingType(): array
    {
        return [
            BookingTypeEnum::CAR_BOOKING->getBookingTypeId() => $this->carBookingOptionFields(),

            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId() => $this->inUnitFoodBookingOptionFields(),

            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId() => $this->outUnitFoodBookingOptionFields(),

            BookingTypeEnum::EQUIPMENT_BOOKING_MALE->getBookingTypeId() => $this->equipmentBookingMaleOptionFields(),

            BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE->getBookingTypeId() => $this->equipmentBookingFemaleOptionFields(),

            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId() => $this->carMaintenanceBookingOptionFields(),

            BookingTypeEnum::CONSTRUCTION_BOOKING->getBookingTypeId() => $this->constructionBookingOptionFields(),

            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId() => $this->transportationBookingOptionFields(),

        ];
    }

    // --------------------------- Hekper function -------------------- //
    private function shouldCreateOptionFieldRecursive(int $bookingTypeId): bool
    {
        return in_array(
            $bookingTypeId,
            [
                BookingTypeEnum::EQUIPMENT_BOOKING_MALE->getBookingTypeId(),
                BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE->getBookingTypeId(),

                // OUT_UNIT_FOOD_BOOKING has nested option fields.
                BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId(),
            ],
            true,
        );
    }

    private function createOptionField(int $formFieldId, array $optionField): void
    {
        $categoryFields = $optionField['category_fields'] ?? [];

        unset($optionField['category_fields']);

        $createdOptionField = OptionField::query()->updateOrCreate(
            [
                'name' => $optionField['name'],
                'form_field_id' => $formFieldId,
            ],
            array_merge($optionField, [
                'form_field_id' => $formFieldId,
                'is_input' => $optionField['is_input'] ?? false,
                'payload_section_id' => $optionField['payload_section_id'] ?? PayloadSectionEnum::NONE->value,
            ]),
        );

        foreach ($categoryFields as $categoryField) {
            $this->createCategoryField(parentOptionFieldId: $createdOptionField->id, formFieldId: $formFieldId, categoryField: $categoryField);
        }
    }

    private function createOptionFieldRecursive(int $formFieldId, array $optionField, ?int $parentId = null): void
    {
        $categoryFields = $optionField['category_fields'] ?? [];

        unset($optionField['category_fields']);

        $createdOptionField = OptionField::query()->updateOrCreate(
            [
                'name' => $optionField['name'],
                'form_field_id' => $formFieldId,
                'option_field_id' => $parentId,
            ],
            array_merge($optionField, [
                'form_field_id' => $formFieldId,
                'option_field_id' => $parentId,
                'is_input' => $optionField['is_input'] ?? false,
                'payload_section_id' => $optionField['payload_section_id'] ?? PayloadSectionEnum::NONE->value,
            ]),
        );

        foreach ($categoryFields as $categoryField) {
            $this->createOptionFieldRecursive(formFieldId: $formFieldId, optionField: $categoryField, parentId: $createdOptionField->id);
        }
    }

    private function createCategoryField(int $parentOptionFieldId, int $formFieldId, array $categoryField): void
    {
        OptionField::query()->updateOrCreate(
            [
                'name' => $categoryField['name'],
                'form_field_id' => $formFieldId,
                'option_field_id' => $parentOptionFieldId,
            ],
            array_merge($categoryField, [
                'form_field_id' => $formFieldId,
                'option_field_id' => $parentOptionFieldId,
                'is_input' => $categoryField['is_input'] ?? false,
                'payload_section_id' => $categoryField['payload_section_id'] ?? PayloadSectionEnum::NONE->value,
            ]),
        );
    }

    private function textOption(string $name, int $formFieldId, int $fieldTypeId = FieldTypeEnum::TEXT->value, bool $isInput = false, int $payloadSectionId = PayloadSectionEnum::NONE->value): array
    {
        return $this->option(name: $name, fieldTypeId: $fieldTypeId, formFieldId: $formFieldId, isInput: $isInput, payloadSectionId: $payloadSectionId);
    }

    private function option(string $name, int $fieldTypeId, int $formFieldId, bool $hasCategory = false, ?string $categoryName = null, bool $isInput = false, int $payloadSectionId = PayloadSectionEnum::NONE->value): array
    {
        return [
            'name' => $name,
            'field_type_id' => $fieldTypeId,
            'form_field_id' => $formFieldId,
            'has_category' => $hasCategory,
            'category_name' => $categoryName,
            'is_input' => $isInput,
            'payload_section_id' => $payloadSectionId,
        ];
    }

    private function categoryOption(string $name, string $categoryName, int $fieldTypeId, int $formFieldId, array $categoryFields, bool $isInput = false, int $payloadSectionId = PayloadSectionEnum::NONE->value): array
    {
        return array_merge($this->option(name: $name, fieldTypeId: $fieldTypeId, formFieldId: $formFieldId, hasCategory: true, categoryName: $categoryName, isInput: $isInput, payloadSectionId: $payloadSectionId), [
            'category_fields' => $categoryFields,
        ]);
    }

    //-------------------------------------- CAR-MAINTANCE-BOOKING --------------------------- //
    private function carMaintenanceBookingOptionFields(): array
    {
        return [
            FormFieldEnum::CAR_MAINTANENCE_COMPANY_NAME->getFormFieldId() => [
                $this->textOption(
                    name: 'אלבר',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_COMPANY_NAME->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'אלדן',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_COMPANY_NAME->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'פרי',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_COMPANY_NAME->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'Avis',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_COMPANY_NAME->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'שלמה סיקסט',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_COMPANY_NAME->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
            ],

            FormFieldEnum::CAR_MAINTANENCE_CORE_PROBLEM->getFormFieldId() => [
                $this->textOption(
                    name: 'טיפול יזום',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_CORE_PROBLEM->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'תיקון תאונה',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_CORE_PROBLEM->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'טסט - רישוי שנתי',
                    formFieldId: FormFieldEnum::CAR_MAINTANENCE_CORE_PROBLEM->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
            ],
        ];
    }

    //-------------------------------------- CONSTRUCTION-BOOKING --------------------------- //

    /** CONSTRUCTION_BOOKING option fields - flat leaf lists under each of the 4 fault category FormFields. */
    private function constructionBookingOptionFields(): array
    {
        $electricityFormFieldId = FormFieldEnum::CONSTRUCTION_ELECTRICITY->getFormFieldId();
        $structureFormFieldId = FormFieldEnum::CONSTRUCTION_STRUCTURE->getFormFieldId();
        $acFormFieldId = FormFieldEnum::CONSTRUCTION_AC->getFormFieldId();
        $plumbingFormFieldId = FormFieldEnum::CONSTRUCTION_PLUMBING->getFormFieldId();

        return array_filter([
            $electricityFormFieldId => $electricityFormFieldId
                ? $this->constructionFaultOptions(
                    names: [
                        'נורה שרופה',
                        'שקע שבור',
                        'מפסק תאורה תקול',
                        'מזגנית לא תקינה',
                    ],
                    formFieldId: $electricityFormFieldId
                )
                : null,

            $structureFormFieldId => $structureFormFieldId
                ? $this->constructionFaultOptions(
                    names: [
                        'מרצפת שבורה',
                        'דלת לא ננעלת',
                        'החלפת צילינדר',
                        'החלפת ידיות',
                        'התקנת סטופרים',
                        'תלייה על קיר גבס',
                        'תלייה על קיר בטון',
                    ],
                    formFieldId: $structureFormFieldId
                )
                : null,

            $acFormFieldId => $acFormFieldId
                ? $this->constructionFaultOptions(
                    names: [
                        'מזגן מטפטף',
                        'מזגן לא מחמם',
                        'מזגן לא מקרר',
                        'מזגן לא עובד',
                    ],
                    formFieldId: $acFormFieldId
                )
                : null,

            $plumbingFormFieldId => $plumbingFormFieldId
                ? $this->constructionFaultOptions(
                    names: [
                        'החלפת מושב אסלה',
                        'ברז מטפטף',
                        'נזילה בכיור',
                        'נזילה בשירותים',
                        'שירותים סתומים',
                    ],
                    formFieldId: $plumbingFormFieldId
                )
                : null,
        ]);
    }

    /** Build flat construction fault leaf options (plain SELECTS, no quantity). @param array $names, int $formFieldId @return array */
    private function constructionFaultOptions(array $names, int $formFieldId): array
    {
        return array_map(
            fn(string $name): array => $this->textOption(
                name: $name,
                formFieldId: $formFieldId,
                payloadSectionId: PayloadSectionEnum::SELECTS->value
            ),
            $names
        );
    }

    //-------------------------------------- TRANSPORTATION-BOOKING --------------------------- //

    /** TRANSPORTATION_BOOKING option fields - flat leaf lists under each of the 4 root select FormFields. */
    private function transportationBookingOptionFields(): array
    {
        $orderTypeFormFieldId = FormFieldEnum::TRANSPORTATION_ORDER_TYPE->getFormFieldId();
        $cargoFormFieldId = FormFieldEnum::TRANSPORTATION_CARGO->getFormFieldId();
        $vehicleTypeFormFieldId = FormFieldEnum::TRANSPORTATION_VEHICLE_TYPE->getFormFieldId();
        $assemblyPointFormFieldId = FormFieldEnum::TRANSPORTATION_ASSEMBLY_POINT->getFormFieldId();

        return array_filter([
            $orderTypeFormFieldId => $orderTypeFormFieldId
                ? $this->transportationOptions(
                    names: [
                        'טיול',
                        'סיור מקצועי',
                        'פינוי כספות',
                        'עבודות בינוי',
                    ],
                    formFieldId: $orderTypeFormFieldId
                )
                : null,

            $cargoFormFieldId => $cargoFormFieldId
                ? $this->transportationOptions(
                    names: [
                        'נוסעים',
                        'מכולה 20 רגל',
                        'ציוד כללי',
                        'רכב גרירה עד 5 טון',
                        'מכולה 40 רגל',
                        'תומ"ת רוכב',
                        'חד"ב ממוגן',
                        'גנרטור רגיל',
                        'מלגזה 8 טון',
                        'גנרטור כללי',
                        'רכב גרירה מעל 5 טון',
                        'נוסעים + ציוד',
                        'כספת ריקה',
                     
                    ],
                    formFieldId: $cargoFormFieldId
                )
                : null,

            $vehicleTypeFormFieldId => $vehicleTypeFormFieldId
                ? $this->transportationOptions(
                    names: [
                        'מידיבוס 34 מקומות',
                        'אוטובוס זעיר הסעות קבע',
                        'משא צ.כפ מש.מנ 100',
                        'סמי צבאי ארוך משטח',
                        'משא עם מנוף 350 טון',
                        'סמי מנוף צבאי',
                        'גרר שכור',
                        'מיניבוס 19 מקומות',
                        'מוסטנג דפנות מתפרקות',
                        'סמי מנוף 100 טון',
                        'משא צ.כפ מש.מנ 60 טון',
                        'סמי משטח שכור',
                        'מוביל עגלה קל/כבד',
                        'מיכלית מים 9000 צבאי',
                        'אוטובוס שכור',
                        'אוטובוס ממוגן אבן',
                        'מיניבוס 14 מקומות',
                        'סמי מנוף 120 טון',
                        '14 מקומות ממוגן אבן',
                    ],
                    formFieldId: $vehicleTypeFormFieldId
                )
                : null,

            $assemblyPointFormFieldId => $assemblyPointFormFieldId
                ? $this->transportationOptions(
                    names: [
                        'ישיר',
                        'תה"ש מחנה - בסיס ציוד',
                        'תה"ש שער דרום',
                        'חניון קרית ההדרכה',
                        'כפר יעבץ',
                        'ראשון לציון מטווח 24',
                        'רעננה תחנה',
                        'פתח תקווה',
                        'כפר סבא',
                        'יהוד',
                        'ראש העין',
                        'קרית נטפים',
                        'מחסום רנטיס',
                        'צוקי עובדה',
                        'ב"ש תחנת רכבת צפון',
                        'דימונה אגד',
                        'פ הסעות שבת 5050',
                        'פלוגות מחנה - ימ"ח',
                        'צומת פלוגות',
                        'מצפה רמון',
                        'מחנה נתן שער ראשי',
                        'שבטה מחנה',
                        'ב"ש חניון יס פלאנט',
                        'צאלים/ בא"פ דרום',
                        'ירדן מחנה',
                        'צריפין / בה"ד קשר',
                        'תה"ש מחנה מקשח"ר',
                        'אילת',
                        'נחל שורק',
                        'אשדוד',
                        'תה"ש מחנה - מש"א',
                        'שובה',
                        'צריפין / לוט"מ',
                        'אלוני אבא',
                        'שדרות',
                        'רמלה מחנה העורף',
                        'צריפין / יפת"ח / חה"ן',
                        'גוליס מחנה',
                        'קסטינה / מז"י (בר לב)',
                        'רעים ב',
                        'כפר עזה',
                        'ניר עוז',
                        'תקומה',
                        'אורים',
                        'בית אל מחנה',
                        'מרחב בנימין',
                        'מחסום חיזמה - פסגת זאב',
                        'ב"ש',
                        'קציעות מחנה',
                        'סוסיא',
                        'משמר הנגב / יחידת הצמ"ה',
                        'מבנה ITT',
                        'ירושלים',
                        'מרחב ירושלים',
                        'שורה מחנה (ליד רמלה)',
                        'לטרון - חניון שריון',
                        'ת"א / יפו',
                        'נאות קדומים',
                        'עופר מחנה',
                        'מרחב אזח"ע',
                        'עלומים',
                        'ת"א תחנת רכבת השלום',
                        'צריפין שער יפו',
                        'זיקים',
                        'בני ברק',
                        'סיירים מחנה 5050',
                    ],
                    formFieldId: $assemblyPointFormFieldId
                )
                : null,

            FormFieldEnum::TRANSPORTATION_NOTES->getFormFieldId() => FormFieldEnum::TRANSPORTATION_NOTES->getFormFieldId()
                ? $this->transportationOptions(
                    names: [
                        'חייב לדייק',
                        'קורה',
                        'נסיעה בכביש 6',
                        'עוזר לעבודה בגובה',
                        'חייב היתר חמ"ס',
                        'חייב ערכת חמ"ס',
                        'נהג ותיק',
                        'חייב ביקורת תחמושת',
                        'הרצה מהירה',
                        'ההובלה חייבת מיגון כללי',
                        'נקי ומסודר',
                        'משימה רגישה',
                        'מפואר עם מיקרופון',
                        'מפואר עם טלוויזיה',
                        'נהג אחראי',
                    ],
                    formFieldId: FormFieldEnum::TRANSPORTATION_NOTES->getFormFieldId()
                )
                : null,
        ]);
    }

    /** Build flat transportation leaf options (plain SELECTS, no quantity). @param array $names, int $formFieldId @return array */
    private function transportationOptions(array $names, int $formFieldId): array
    {
        return array_map(
            fn(string $name): array => $this->textOption(
                name: $name,
                formFieldId: $formFieldId,
                payloadSectionId: PayloadSectionEnum::SELECTS->value
            ),
            $names
        );
    }

    //------------------------  CAR-BOOKING -------------------------- //
    private function carBookingOptionFields(): array
    {
        return [
            FormFieldEnum::CAR_BOOKING_DRIVE_PURPOSE->getFormFieldId() => [
                $this->textOption(
                    name: 'משימה',
                    formFieldId: FormFieldEnum::CAR_BOOKING_DRIVE_PURPOSE->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'נופש קבע',
                    formFieldId: FormFieldEnum::CAR_BOOKING_DRIVE_PURPOSE->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                )
            ],
        ];
    }

    //-----------------------IN_UNIT_FOOD_BOOKING ----------------------//

    /** IN_UNIT_FOOD_BOOKING option fields - same flat shape as CAR_BOOKING's drive_purpose. */
    private function inUnitFoodBookingOptionFields(): array
    {
        return [
            FormFieldEnum::IN_UNIT_REFRESHMENTS->getFormFieldId() => [
                $this->textOption(
                    name: 'כיבוד קל',
                    formFieldId: FormFieldEnum::IN_UNIT_REFRESHMENTS->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
                $this->textOption(
                    name: 'כיבוד עשיר',
                    formFieldId: FormFieldEnum::IN_UNIT_REFRESHMENTS->getFormFieldId(),
                    payloadSectionId: PayloadSectionEnum::SELECTS->value
                ),
            ],
        ];
    }

    //-----------------------------OUT_UNIT_FOOD_BOOKING ----------------------//

    /** OUT_UNIT_FOOD_BOOKING option fields. @return array */
    private function outUnitFoodBookingOptionFields(): array
    {
        $sandwichesFormFieldId = FormFieldEnum::OUT_UNIT_SANDWICHES->getFormFieldId();
        $traysFormFieldId = FormFieldEnum::OUT_UNIT_TRAYS->getFormFieldId();

        $dairyFormFieldId = FormFieldEnum::OUT_UNIT_DAIRY->getFormFieldId();
        $meatFormFieldId = FormFieldEnum::OUT_UNIT_MEAT->getFormFieldId();
        $parveFormFieldId = FormFieldEnum::OUT_UNIT_PARVE->getFormFieldId();

        return array_filter([
            $sandwichesFormFieldId => $sandwichesFormFieldId
                ? [
                    $this->sandwichDeliveryOption(
                        name: 'בתוך בסיס בלי בקבוק מים',
                        formFieldId: $sandwichesFormFieldId
                    ),
                    $this->sandwichDeliveryOption(
                        name: 'מחוץ לבסיס עם בקבוק מים',
                        formFieldId: $sandwichesFormFieldId
                    ),
                ]
                : null,

            $traysFormFieldId => $traysFormFieldId
                ? [
                    $this->trayPackageOption(
                        name: 'חמגשית בלבד',
                        formFieldId: $traysFormFieldId
                    ),
                    $this->trayPackageOption(
                        name: 'חמגשית עם נלווים',
                        formFieldId: $traysFormFieldId
                    ),
                ]
                : null,

            $dairyFormFieldId => $dairyFormFieldId
                ? $this->sandwichDietOptions($dairyFormFieldId)
                : null,

            $meatFormFieldId => $meatFormFieldId
                ? $this->sandwichDietOptions($meatFormFieldId)
                : null,

            $parveFormFieldId => $parveFormFieldId
                ? $this->sandwichDietOptions($parveFormFieldId)
                : null,
        ]);
    }


    /** OUT_UNIT_FOOD_BOOKING sandwich delivery option (leaf); frontend sends its UUID in form_fields.items[]. */
    private function sandwichDeliveryOption(
        string $name,
        int $formFieldId
    ): array {
        return $this->option(
            name: $name,
            fieldTypeId: FieldTypeEnum::TEXT->value,
            formFieldId: $formFieldId,
            hasCategory: false,
            categoryName: null,
            isInput: false,
            payloadSectionId: PayloadSectionEnum::SELECTS->value
        );
    }

    /** OUT_UNIT_FOOD_BOOKING diet amount option fields: leaf children of the dairy/meat/parve FormFields. */
    private function sandwichDietOptions(int $formFieldId): array
    {
        return [
            $this->sandwichDietAmountInputOption(
                name: 'טבעוני',
                formFieldId: $formFieldId
            ),
            $this->sandwichDietAmountInputOption(
                name: 'צמחוני',
                formFieldId: $formFieldId
            ),
            $this->sandwichDietAmountInputOption(
                name: 'ללא גלוטן',
                formFieldId: $formFieldId
            ),
            $this->sandwichDietAmountInputOption(
                name: 'כשרות מיוחדת',
                formFieldId: $formFieldId
            ),
        ];
    }

    /** Diet amount input option (leaf); frontend sends its UUID in form_fields.items[]. */
    private function sandwichDietAmountInputOption(
        string $name,
        int $formFieldId
    ): array {
        return $this->option(
            name: $name,
            fieldTypeId: FieldTypeEnum::NUMBER->value,
            formFieldId: $formFieldId,
            hasCategory: false,
            categoryName: null,
            isInput: true,
            payloadSectionId: PayloadSectionEnum::ITEMS->value
        );
    }


    /** OUT_UNIT_FOOD_BOOKING tray package option (leaf); frontend sends its UUID in form_fields.items[]. */
    private function trayPackageOption(
        string $name,
        int $formFieldId
    ): array {
        return $this->option(
            name: $name,
            fieldTypeId: FieldTypeEnum::TEXT->value,
            formFieldId: $formFieldId,
            hasCategory: false,
            categoryName: null,
            isInput: false,
            payloadSectionId: PayloadSectionEnum::SELECTS->value
        );
    }



    //------------------- EQUIPMENT-BOOKING-MALE -------------------------------- //

    /** EQUIPMENT_BOOKING_MALE option fields - clothing_male/footwear_male items are promoted to FormFields. */
    private function equipmentBookingMaleOptionFields(): array
    {
        $relatedProductsMaleFormFieldId = FormFieldEnum::RELATED_PRODUCTS_MALE->getFormFieldId();

        $clothingMaleShirtFormFieldId = FormFieldEnum::CLOTHING_MALE_SHIRT->getFormFieldId();
        $clothingMalePantsFormFieldId = FormFieldEnum::CLOTHING_MALE_PANTS->getFormFieldId();
        $footwearMaleInfantryFormFieldId = FormFieldEnum::FOOTWEAR_MALE_INFANTRY->getFormFieldId();
        $footwearMaleVeganFormFieldId = FormFieldEnum::FOOTWEAR_MALE_VEGAN->getFormFieldId();
        $clothingMaleCottonUniformFormFieldId = FormFieldEnum::CLOTHING_MALE_COTTON_UNIFORM->getFormFieldId();

        return array_filter([
            $clothingMaleShirtFormFieldId => $clothingMaleShirtFormFieldId
                ? [
                    ...$this->maleTextOptions(
                        names: [
                            'חולצה ק',
                            'חולצה ב',
                            'חולצה ג',
                            'חולצה מ',
                            'חולצה ממ',
                            'חולצה מממ',
                        ],
                        formFieldId: $clothingMaleShirtFormFieldId
                    ),
                    $this->maleOtherSizeOption($clothingMaleShirtFormFieldId),
                ]
                : null,

            $clothingMalePantsFormFieldId => $clothingMalePantsFormFieldId
                ? $this->maleTextOptions(
                    names: [
                        '36',
                        '38',
                        '40',
                        '42',
                        '44',
                        '46',
                        '48',
                        '50',
                        '52',
                        '54',
                        '56',
                        '58',
                        '60',
                        '62',
                    ],
                    formFieldId: $clothingMalePantsFormFieldId
                )
                : null,

            $footwearMaleInfantryFormFieldId => $footwearMaleInfantryFormFieldId
                ? $this->maleNumberOptions(
                    names: [
                        '37',
                        '38',
                        '39',
                        '40',
                        '41',
                        '42',
                        '43',
                        '44',
                        '45',
                        '46',
                        '47',
                        '48',
                    ],
                    formFieldId: $footwearMaleInfantryFormFieldId
                )
                : null,

            $footwearMaleVeganFormFieldId => $footwearMaleVeganFormFieldId
                ? $this->maleNumberOptions(
                    names: [
                        '37',
                        '38',
                        '39',
                        '40',
                        '41',
                        '42',
                        '43',
                        '44',
                        '45',
                        '46',
                        '47',
                        '48',
                    ],
                    formFieldId: $footwearMaleVeganFormFieldId
                )
                : null,

            $relatedProductsMaleFormFieldId => [
                $this->maleCategoryOption(
                    name: 'סוודר',
                    categoryName: 'בחירת מידה',
                    fieldTypeId: FieldTypeEnum::SELECT->value,
                    formFieldId: $relatedProductsMaleFormFieldId,
                    categoryFields: $this->maleTextOptions(
                        names: [
                            'מידה ק',
                            'מידה ב',
                            'מידה ג',
                            'מידה מ',
                            'מידה ממ',
                            'מידה מממ',
                        ],
                        formFieldId: $relatedProductsMaleFormFieldId
                    )
                ),

                $this->maleCategoryOption(
                    name: 'סופטשל א',
                    categoryName: 'בחירת מידה',
                    fieldTypeId: FieldTypeEnum::SELECT->value,
                    formFieldId: $relatedProductsMaleFormFieldId,
                    categoryFields: $this->maleTextOptions(
                        names: [
                            'S',
                            'M',
                            'L',
                            'XL',
                            'XXL',
                            'XXXL',
                        ],
                        formFieldId: $relatedProductsMaleFormFieldId
                    )
                ),

                $this->maleTextOption(
                    name: 'חגורה',
                    formFieldId: $relatedProductsMaleFormFieldId
                ),

                $this->maleTextOption(
                    name: 'כומתת תקשוב',
                    formFieldId: $relatedProductsMaleFormFieldId
                ),

                $this->maleTextOption(
                    name: 'כומתת בקום',
                    formFieldId: $relatedProductsMaleFormFieldId
                ),

                $this->maleTextOption(
                    name: 'כומתה בהתאמה אישית',
                    formFieldId: $relatedProductsMaleFormFieldId
                ),
            ],

            // Promoted to its own FormField - its two sub-items keep the same category+sizes shape, re-parented.
            $clothingMaleCottonUniformFormFieldId => $clothingMaleCottonUniformFormFieldId
                ? [
                    $this->maleCategoryOption(
                        name: 'מכנסי גברים',
                        categoryName: 'בחירת מידה',
                        fieldTypeId: FieldTypeEnum::SELECT->value,
                        formFieldId: $clothingMaleCottonUniformFormFieldId,
                        categoryFields: $this->maleNumberOptions(
                            names: [
                                '36',
                                '38',
                                '40',
                                '42',
                                '44',
                                '46',
                                '48',
                                '50',
                                '52',
                                '54',
                                '56',
                                '58',
                                '60',
                                '62',
                            ],
                            formFieldId: $clothingMaleCottonUniformFormFieldId
                        )
                    ),

                    $this->maleCategoryOption(
                        name: 'חולצות גברים',
                        categoryName: 'בחירת מידה',
                        fieldTypeId: FieldTypeEnum::SELECT->value,
                        formFieldId: $clothingMaleCottonUniformFormFieldId,
                        categoryFields: [
                            ...$this->maleTextOptions(
                                names: [
                                    'חולצה ק',
                                    'חולצה ב',
                                    'חולצה ג',
                                    'חולצה מ',
                                    'חולצה ממ',
                                    'חולצה מממ',
                                ],
                                formFieldId: $clothingMaleCottonUniformFormFieldId
                            ),
                            $this->maleOtherSizeOption($clothingMaleCottonUniformFormFieldId),
                        ]
                    ),
                ]
                : null,
        ]);
    }

    //--------------------------------- EQUIPMENT-BOOKING-FEMALE ----------------------------//
    /** EQUIPMENT_BOOKING_FEMALE option fields - same idea as the male version, promoted to FormFields. */
    private function equipmentBookingFemaleOptionFields(): array
    {
        $relatedProductsFemaleFormFieldId = FormFieldEnum::RELATED_PRODUCTS_FEMALE->getFormFieldId();

        $clothingFemaleShirtFormFieldId = FormFieldEnum::CLOTHING_FEMALE_SHIRT->getFormFieldId();
        $clothingFemalePantsFormFieldId = FormFieldEnum::CLOTHING_FEMALE_PANTS->getFormFieldId();
        $footwearFemaleShoesFormFieldId = FormFieldEnum::FOOTWEAR_FEMALE_SHOES->getFormFieldId();
        $footwearFemaleVeganFormFieldId = FormFieldEnum::FOOTWEAR_FEMALE_VEGAN->getFormFieldId();
        $footwearFemaleInfantryFormFieldId = FormFieldEnum::FOOTWEAR_FEMALE_INFANTRY->getFormFieldId();
        $clothingFemaleCottonUniformFormFieldId = FormFieldEnum::CLOTHING_FEMALE_COTTON_UNIFORM->getFormFieldId();

        return array_filter([
            $clothingFemaleShirtFormFieldId => $clothingFemaleShirtFormFieldId
                ? [
                    ...$this->femaleTextOptions(
                        names: [
                            'חולצה ק',
                            'חולצה ב',
                            'חולצה ג',
                            'חולצה מ',
                            'חולצה ממ',
                            'חולצה מממ',
                        ],
                        formFieldId: $clothingFemaleShirtFormFieldId
                    ),
                    $this->femaleOtherSizeOption($clothingFemaleShirtFormFieldId),
                ]
                : null,

            $clothingFemalePantsFormFieldId => $clothingFemalePantsFormFieldId
                ? $this->femaleNumberOptions(
                    names: [
                        '36',
                        '38',
                        '40',
                        '42',
                        '44',
                        '46',
                        '48',
                        '50',
                        '52',
                        '54',
                        '56',
                        '58',
                        '60',
                        '62',
                    ],
                    formFieldId: $clothingFemalePantsFormFieldId
                )
                : null,

            $footwearFemaleShoesFormFieldId => $footwearFemaleShoesFormFieldId
                ? $this->femaleNumberOptions(
                    names: [
                        '35',
                        '36',
                        '37',
                        '38',
                        '39',
                        '40',
                        '41',
                        '42',
                        '43',
                        '44',
                        '45',
                        '46',
                        '47',
                        '48',
                    ],
                    formFieldId: $footwearFemaleShoesFormFieldId
                )
                : null,

            $footwearFemaleVeganFormFieldId => $footwearFemaleVeganFormFieldId
                ? $this->femaleNumberOptions(
                    names: [
                        '37',
                        '38',
                        '39',
                        '40',
                        '41',
                        '42',
                        '43',
                        '44',
                        '45',
                        '46',
                        '47',
                        '48',
                    ],
                    formFieldId: $footwearFemaleVeganFormFieldId
                )
                : null,

            $footwearFemaleInfantryFormFieldId => $footwearFemaleInfantryFormFieldId
                ? $this->femaleNumberOptions(
                    names: [
                        '37',
                        '38',
                        '39',
                        '40',
                        '41',
                        '42',
                        '43',
                        '44',
                        '45',
                        '46',
                        '47',
                        '48',
                    ],
                    formFieldId: $footwearFemaleInfantryFormFieldId
                )
                : null,

            $relatedProductsFemaleFormFieldId => [
                $this->femaleCategoryOption(
                    name: 'סוודר',
                    categoryName: 'בחירת מידה',
                    fieldTypeId: FieldTypeEnum::SELECT->value,
                    formFieldId: $relatedProductsFemaleFormFieldId,
                    categoryFields: $this->femaleTextOptions(
                        names: [
                            'מידה ק',
                            'מידה ב',
                            'מידה ג',
                            'מידה מ',
                            'מידה ממ',
                            'מידה מממ',
                        ],
                        formFieldId: $relatedProductsFemaleFormFieldId
                    )
                ),

                $this->femaleCategoryOption(
                    name: 'סופטשל א',
                    categoryName: 'בחירת מידה',
                    fieldTypeId: FieldTypeEnum::SELECT->value,
                    formFieldId: $relatedProductsFemaleFormFieldId,
                    categoryFields: $this->femaleTextOptions(
                        names: [
                            'S',
                            'M',
                            'L',
                            'XL',
                            'XXL',
                            'XXXL',
                        ],
                        formFieldId: $relatedProductsFemaleFormFieldId
                    )
                ),

                $this->femaleTextOption(
                    name: 'חגורה',
                    formFieldId: $relatedProductsFemaleFormFieldId
                ),

                $this->femaleTextOption(
                    name: 'כומתת תקשוב',
                    formFieldId: $relatedProductsFemaleFormFieldId
                ),

                $this->femaleTextOption(
                    name: 'כומתת בקום',
                    formFieldId: $relatedProductsFemaleFormFieldId
                ),

                $this->femaleTextOption(
                    name: 'כומתה בהתאמה אישית',
                    formFieldId: $relatedProductsFemaleFormFieldId
                ),
            ],

            // Promoted to its own FormField - its two sub-items keep the same category+sizes shape, re-parented.
            $clothingFemaleCottonUniformFormFieldId => $clothingFemaleCottonUniformFormFieldId
                ? [
                    $this->femaleCategoryOption(
                        name: 'חולצות נשים',
                        categoryName: 'בחירת מידה',
                        fieldTypeId: FieldTypeEnum::SELECT->value,
                        formFieldId: $clothingFemaleCottonUniformFormFieldId,
                        categoryFields: [
                            ...$this->femaleTextOptions(
                                names: [
                                    'חולצה ק',
                                    'חולצה ב',
                                    'חולצה ג',
                                    'חולצה מ',
                                    'חולצה ממ',
                                    'חולצה מממ',
                                ],
                                formFieldId: $clothingFemaleCottonUniformFormFieldId
                            ),
                            $this->femaleOtherSizeOption($clothingFemaleCottonUniformFormFieldId),
                        ]
                    ),

                    $this->femaleCategoryOption(
                        name: 'מכנסי נשים',
                        categoryName: 'בחירת מידה',
                        fieldTypeId: FieldTypeEnum::SELECT->value,
                        formFieldId: $clothingFemaleCottonUniformFormFieldId,
                        categoryFields: $this->femaleNumberOptions(
                            names: [
                                '36',
                                '38',
                                '40',
                                '42',
                                '44',
                                '46',
                                '48',
                                '50',
                                '52',
                                '54',
                                '56',
                                '58',
                                '60',
                            ],
                            formFieldId: $clothingFemaleCottonUniformFormFieldId
                        )
                    ),
                ]
                : null,
        ]);
    }

    //----------------------- Helper Function MALE & FEMALE ----------------------- //

    // MALE
    private function maleTextOptions(
        array $names,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return array_map(
            fn(string $name): array => $this->maleTextOption(
                name: $name,
                formFieldId: $formFieldId,
                payloadSectionId: $payloadSectionId
            ),
            $names
        );
    }

    private function maleNumberOptions(
        array $names,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return array_map(
            fn(string $name): array => $this->maleNumberOption(
                name: $name,
                formFieldId: $formFieldId,
                payloadSectionId: $payloadSectionId
            ),
            $names
        );
    }

    private function maleTextOption(
        string $name,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return $this->genderOption(
            name: $name,
            fieldTypeId: FieldTypeEnum::TEXT->value,
            formFieldId: $formFieldId,
            gender: GenderEnum::MALE->value,
            payloadSectionId: $payloadSectionId
        );
    }

    private function maleNumberOption(
        string $name,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return $this->genderOption(
            name: $name,
            fieldTypeId: FieldTypeEnum::NUMBER->value,
            formFieldId: $formFieldId,
            gender: GenderEnum::MALE->value,
            payloadSectionId: $payloadSectionId
        );
    }
    private function maleCategoryOption(
        string $name,
        string $categoryName,
        int $fieldTypeId,
        int $formFieldId,
        array $categoryFields,
        int $payloadSectionId = PayloadSectionEnum::NONE->value
    ): array {
        return array_merge(
            $this->genderOption(
                name: $name,
                fieldTypeId: $fieldTypeId,
                formFieldId: $formFieldId,
                gender: GenderEnum::MALE->value,
                hasCategory: true,
                categoryName: $categoryName,
                payloadSectionId: $payloadSectionId
            ),
            [
                'category_fields' => $categoryFields,
            ]
        );
    }

    // FEMALE
    private function femaleTextOptions(
        array $names,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return array_map(
            fn(string $name): array => $this->femaleTextOption(
                name: $name,
                formFieldId: $formFieldId,
                payloadSectionId: $payloadSectionId
            ),
            $names
        );
    }
    private function femaleNumberOptions(
        array $names,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return array_map(
            fn(string $name): array => $this->femaleNumberOption(
                name: $name,
                formFieldId: $formFieldId,
                payloadSectionId: $payloadSectionId
            ),
            $names
        );
    }

    private function femaleTextOption(
        string $name,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return $this->genderOption(
            name: $name,
            fieldTypeId: FieldTypeEnum::TEXT->value,
            formFieldId: $formFieldId,
            gender: GenderEnum::FEMALE->value,
            payloadSectionId: $payloadSectionId
        );
    }

    private function femaleNumberOption(
        string $name,
        int $formFieldId,
        int $payloadSectionId = PayloadSectionEnum::ITEMS->value
    ): array {
        return $this->genderOption(
            name: $name,
            fieldTypeId: FieldTypeEnum::NUMBER->value,
            formFieldId: $formFieldId,
            gender: GenderEnum::FEMALE->value,
            payloadSectionId: $payloadSectionId
        );
    }
    private function femaleCategoryOption(
        string $name,
        string $categoryName,
        int $fieldTypeId,
        int $formFieldId,
        array $categoryFields,
        int $payloadSectionId = PayloadSectionEnum::NONE->value
    ): array {
        return array_merge(
            $this->genderOption(
                name: $name,
                fieldTypeId: $fieldTypeId,
                formFieldId: $formFieldId,
                gender: GenderEnum::FEMALE->value,
                hasCategory: true,
                categoryName: $categoryName,
                payloadSectionId: $payloadSectionId
            ),
            [
                'category_fields' => $categoryFields,
            ]
        );
    }


    private function genderOption(
        string $name,
        int $fieldTypeId,
        int $formFieldId,
        int $gender,
        bool $hasCategory = false,
        ?string $categoryName = null,
        bool $isInput = false,
        int $payloadSectionId = PayloadSectionEnum::NONE->value
    ): array {
        return [
            'name' => $name,
            'field_type_id' => $fieldTypeId,
            'form_field_id' => $formFieldId,
            'gender' => $gender,
            'has_category' => $hasCategory,
            'category_name' => $categoryName,
            'is_input' => $isInput,
            'payload_section_id' => $payloadSectionId,
        ];
    }

    /** "Other" size: a flat sibling of the fixed size leaves, but is_input=true opens a free-text input. */
    private function otherSizeOption(int $gender, int $formFieldId): array
    {
        return $this->genderOption(
            name: 'אחר',
            fieldTypeId: FieldTypeEnum::TEXT->value,
            formFieldId: $formFieldId,
            gender: $gender,
            isInput: true,
            payloadSectionId: PayloadSectionEnum::ITEMS->value
        );
    }

    private function maleOtherSizeOption(int $formFieldId): array
    {
        return $this->otherSizeOption(gender: GenderEnum::MALE->value, formFieldId: $formFieldId);
    }

    private function femaleOtherSizeOption(int $formFieldId): array
    {
        return $this->otherSizeOption(gender: GenderEnum::FEMALE->value, formFieldId: $formFieldId);
    }


    /** Recursively create OptionField records from a nested $data array. */
    private function seedOptionField(array $node, ?int $parentId = null, $selectedFieldTypeIds = []): OptionField
    {
        // Basic guard rails + defaults
        $payload = [
            'uuid' => Str::uuid(),
            'name' => Arr::get($node, 'name'),
            'category_name' => Arr::get($node, 'category_name', null),
            'form_field_id' => Arr::get($node, 'form_field_id'),
            'field_type_id' => Arr::get($node, 'field_type_id'),
            'has_category' => (bool) Arr::get($node, 'has_category', false),
            'gender' => Arr::get($node, 'gender', GenderEnum::NONE->value),
            'option_field_id' => $parentId, // parent link
        ];

        /** @var OptionField $created */
        $created = OptionField::create($payload);

        $isTextLeaf = in_array($created->field_type_id, $selectedFieldTypeIds) && !$created->has_category;
        $children = Arr::get($node, 'category_fields', []);

        if ($isTextLeaf || empty($children)) {
            return $created;
        }

        // Recurse for children
        foreach ($children as $child) {
            $this->seedOptionField($child, $created->id);
        }

        return $created;
    }


   
}

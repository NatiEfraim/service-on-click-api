<?php

namespace App\Http\Resources;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\GenderEnum;
use App\Enums\SystemReferenceEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\FormField;
use App\Models\SystemReference;

class FormFieldResource extends JsonResource
{
    private ?int $gender = null;

    public function with(Request $request): array
    {
        $this->gender = $this->additional['gender'] ?? null;
        return [];
    }
    /** Transform the resource into an array. @param Request $request @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->removeNullValues([
            'id' => $this->id,
            'uuid' => $this->uuid,
            'is_required' => (bool) $this->is_required,
            'is_input' => (bool) $this->is_input,
            'field_display_name' => $this->field_display_name,
            'payload_section_id' => $this->payload_section_id,
            'field_sub_display_name' => $this->retrieveFormFieldSubDisplayName(),
            'field_type' => $this->retrieveFieldTypeObject(),
            'field_restrictions' => $this->retrieveFieldRestrictionsByFormField($this->resource),
            'form_fields' => $this->getFormFieldRecursive($this->resource),
            'option_fields' => $this->getOptionFieldsByFormField($this->resource),

        ]);

    }

    /** Remove only null values from array. @param array $data @return array */
    private function removeNullValues(array $data): array
    {
        return array_filter($data, fn($value) => !is_null($value));
    }


    /** Get child form fields recursively. */
    private function getFormFieldRecursive(?FormField $formField=null): ?array
    {
        if (!$formField->relationLoaded('childFormFields') || $formField->childFormFields->isEmpty()) {
            return null;
        }

        return $formField->childFormFields
            ->map(function (FormField $childFormField) {
                return array_filter([
                    'id' => $childFormField->id,
                    'uuid' => $childFormField->uuid,
                    'is_required' => (bool) $childFormField->is_required,
                    'is_input' => (bool) $childFormField->is_input,
                    'payload_section_id' => (int) $childFormField->payload_section_id,
                    'field_display_name' => $childFormField->field_display_name,
                    'field_sub_display_name' => $this->retrieveFormFieldSubDisplayName($childFormField),
                    'field_type' => [
                        'id' => $childFormField->fieldType?->id,
                        'name' => $childFormField->fieldType?->name
                    ],
                    'form_field_id' => $childFormField->form_field_id,
                    'field_restrictions' => $this->retrieveFieldRestrictionsByFormField($childFormField),
                    'option_fields' => $this->getOptionFieldsByFormField($childFormField),
                    'form_fields' => $this->getFormFieldRecursive($childFormField),
                ], fn($value) => !is_null($value));
            })
            ->values()
            ->toArray();
    }



    /** Resolve the field type name, with special handling for workspace time fields. */
    private function retrieveFieldTypeObject(): ?array
    {

        $formFieldIdByBookingType = match ($this->booking_type_id) {
            BookingTypeEnum::LECTURE_ROOM_BOOKING->getBookingTypeId() => FormFieldEnum::LECTURE_ROOM_TIME->getFormFieldId(),
            BookingTypeEnum::SMALL_COMMAND_ROOM_BOOKING->getBookingTypeId() => FormFieldEnum::SMALL_COMMAND_ROOM_TIME->getFormFieldId(),
            BookingTypeEnum::TECH_HAMAMA_FIRST_BOOKING->getBookingTypeId() => FormFieldEnum::TECH_HAMAMA_FIRST_TIME->getFormFieldId(),
            BookingTypeEnum::TECH_HAMAMA_SECOND_BOOKING->getBookingTypeId() => FormFieldEnum::TECH_HAMAMA_SECOND_TIME->getFormFieldId(),
            default => null,
        };

        if (
            $formFieldIdByBookingType &&
            $this->id === $formFieldIdByBookingType &&
            (int) $this->field_type_id === FieldTypeEnum::MULTI_SELECT->value
        ) {
            return [
                'id' => FieldTypeEnum::TIME->value,
                'name' => 'time',
            ];
        }

        return [
            'id' => $this->fieldType?->id,
            'name' => $this->fieldType?->name,
        ];
    }



    /** Resolve the sub display_name for a form_field record, defaulting to the resource's own root field. */
    private function retrieveFormFieldSubDisplayName(?FormField $formField = null): ?string
    {
        $formField ??= $this->resource;

        return FormFieldEnum::fromFieldName($formField->field_name)
                ?->getFormFieldSubDisplayName();
    }

    private function resolveOutUnitFoodBookingRestrictionsByFormField(
        FormField $formField,
        SystemReferenceEnum $enum,
        array $value
    ): ?array {
        return match ($enum) {
            SystemReferenceEnum::OUT_UNIT_FOOD_MINIMUM_ORDER_AMOUNT =>
            $formField->field_type_id === FieldTypeEnum::NUMBER->value
            ? ['min' => $value['min'] ?? null]
            : null,

            default => null,
        };
    }

    /** Resolve IN_UNIT_FOOD_BOOKING restrictions for child form fields. */
    private function resolveInUnitFoodBookingRestrictionsByFormField(
        FormField $formField,
        SystemReferenceEnum $enum,
        array $value
    ): ?array {
        return match ($enum) {
            SystemReferenceEnum::IN_UNIT_FOOD_PEOPLE_AMOUNT =>
            $formField->field_type_id === FieldTypeEnum::NUMBER->value
            ? [
                'multiple_of' => $value['multiple_of'] ?? null,
            ]
            : null,

            default => null,
        };
    }



    /** Public entry to the restriction resolving, reused e.g. by BookingValueFormatterService; needs systemReferences loaded. @return ?array */
    public function resolveFieldRestrictions(): ?array
    {
        return $this->retrieveFieldRestrictionsByFormField($this->resource);
    }

    /** Retrieve field restrictions for any FormField model - used mainly for child form fields. */
    private function retrieveFieldRestrictionsByFormField(FormField $formField): ?array
    {
        if (!$formField->relationLoaded('systemReferences') || $formField->systemReferences->isEmpty()) {
            return null;
        }


        $restrictions = $formField->systemReferences
            ->map(function (SystemReference $systemReference) use ($formField) {
                $enum = SystemReferenceEnum::fromSystemReferenceName($systemReference->name);
                if (!$enum) {
                    return null;
                }
                $value = $systemReference->decodedValue();
                return match ($enum) {
                        // BARBER_BOOKING
                    SystemReferenceEnum::BARBER_SERVICE_HOURS,
                    SystemReferenceEnum::BARBER_BREAK_HOURS,
                    SystemReferenceEnum::BARBER_HAIRCUT_TIME,
                    SystemReferenceEnum::BARBER_ADVANCE_TIME,
                    SystemReferenceEnum::BARBER_RESTRICTION_DATES
                    => $this->resolveBarberBookingRestrictionsByFormField(
                        formField: $formField,
                        enum: $enum,
                        value: $value
                    ),
                        // CAR-BOOKING
                    SystemReferenceEnum::CAR_SERVICE_HOURS,
                    SystemReferenceEnum::CAR_RESTRICTIONS
                    => $this->resolveCarBookingRestrictionsByFormField(
                        formField: $formField,
                        enum: $enum,
                        value: $value
                    ),

                        // FOOD-BOOKING
                    SystemReferenceEnum::FOOD_SERVICE_HOURS,
                     SystemReferenceEnum::FOOD_RESTRICTIONS =>
                      $this->resolveFoodBookingRestrictions($enum,$value),

                        // IN-UNIT-FOOD-BOOKING
                    SystemReferenceEnum::IN_UNIT_FOOD_SERVICE_HOURS,
                    SystemReferenceEnum::IN_UNIT_FOOD_RESTRICTIONS,
                    SystemReferenceEnum::IN_UNIT_FOOD_COUNT_BUSINESS_DAYS,
                    SystemReferenceEnum::IN_UNIT_FOOD_MAX_ADVANCE_DAYS,

                    => $this->resolveInUnitFoodBookingRestrictions($enum, $value),
                        // IN-UNIT-FOOD-BOOKING
                    SystemReferenceEnum::IN_UNIT_FOOD_PEOPLE_AMOUNT
                    => $this->resolveInUnitFoodBookingRestrictionsByFormField(
                        formField: $formField,
                        enum: $enum,
                        value: $value
                    ),

                        // OUT_UNIT_FOOD_BOOKING
                    SystemReferenceEnum::OUT_UNIT_FOOD_SERVICE_HOURS,
                    SystemReferenceEnum::OUT_UNIT_FOOD_RESTRICTIONS,
                    SystemReferenceEnum::OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS,
                    SystemReferenceEnum::OUT_UNIT_FOOD_MAX_ADVANCE_DAYS
                    => $this->resolveOutUnitFoodBookingRestrictions($enum, $value),
                        // OUT_UNIT_FOOD_BOOKING
                    SystemReferenceEnum::OUT_UNIT_FOOD_MINIMUM_ORDER_AMOUNT
                    => $this->resolveOutUnitFoodBookingRestrictionsByFormField(
                        formField: $formField,
                        enum: $enum,
                        value: $value
                    ),

                        // EQUIPMENT BOOKING MALE / FEMALE
                    SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_CLOTHING,
                    SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_FOOTWEAR,
                    SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_RELATED_PRODUCTS,

                    SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_CLOTHING,
                    SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_FOOTWEAR,
                    SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_RELATED_PRODUCTS
                    => $this->resolveEquipmentBookingRestrictions($enum, $value),

                        // TRANSPORTATION_BOOKING
                    SystemReferenceEnum::TRANSPORTATION_SERVICE_HOURS,
                    SystemReferenceEnum::TRANSPORTATION_MINIMUM_ADVANCE_DAYS,
                    SystemReferenceEnum::TRANSPORTATION_RESTRICTION_DATES
                    => $this->resolveTransportationBookingRestrictionsByFormField(
                        formField: $formField,
                        enum: $enum,
                        value: $value
                    ),

                        // CAR_MAINTENANCE_BOOKING
                    SystemReferenceEnum::CAR_MAINTENANCE_RESTRICTION_DATES
                    => $this->resolveCarMaintenanceBookingRestrictionsByFormField(
                        formField: $formField,
                        enum: $enum,
                        value: $value
                    ),

                      //WORK-SPACE-BOOKING

                    SystemReferenceEnum::LECTURE_ROOM_SERVICE_HOURS,
                    SystemReferenceEnum::LECTURE_ROOM_RESTRICTIONS,
                    SystemReferenceEnum::LECTURE_ROOM_DURATION_TIME,

                    SystemReferenceEnum::SMALL_COMMNAD_ROOM_SERVICE_HOURS,
                    SystemReferenceEnum::SMALL_COMMNAD_ROOM_RESTRICTIONS,
                    SystemReferenceEnum::SMALL_COMMNAD_ROOM_DURATION_TIME,

                    SystemReferenceEnum::TECH_HAMAMA_FIRST_SERVICE_HOURS,
                    SystemReferenceEnum::TECH_HAMAMA_FIRST_RESTRICTIONS,
                    SystemReferenceEnum::TECH_HAMAMA_FIRST_DURATION_TIME,

                    SystemReferenceEnum::TECH_HAMAMA_SECOND_SERVICE_HOURS,
                    SystemReferenceEnum::TECH_HAMAMA_SECOND_RESTRICTIONS,
                    SystemReferenceEnum::TECH_HAMAMA_SECOND_DURATION_TIME,

                    =>
                    $this->resolveWorkSpaceRestrictions($enum, $value),
                    default => null,
                };
            })
            ->filter()
            ->reduce(function ($carry, $item) {
                return array_merge($carry, $item);
            }, []);

        return empty($restrictions) ? null : $restrictions;

    }

    /** Resolve BARBER_BOOKING field restrictions by actual form field. */
    private function resolveBarberBookingRestrictionsByFormField(
        FormField $formField,
        SystemReferenceEnum $enum,
        array $value
    ): ?array {
        return match ($enum) {
            SystemReferenceEnum::BARBER_SERVICE_HOURS =>
            $formField->field_type_id === FieldTypeEnum::TIME->value
            ? ['service_hours' => $value]
            : null,

            SystemReferenceEnum::BARBER_BREAK_HOURS =>
            $formField->field_type_id === FieldTypeEnum::TIME->value
            ? ['break_service_hours' => $value]
            : null,

            SystemReferenceEnum::BARBER_HAIRCUT_TIME =>
            $formField->field_type_id === FieldTypeEnum::TIME->value
            ? ['haircut_time' => $value['haircut_time'] ?? null]
            : null,

            SystemReferenceEnum::BARBER_ADVANCE_TIME =>
            $formField->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,

            SystemReferenceEnum::BARBER_RESTRICTION_DATES =>
            $formField->field_type_id === FieldTypeEnum::DATE->value
            ? [
                'blocked_dates' => $this->resolveBlockedDatesByBookingType(
                    BookingTypeEnum::BARBER_BOOKING->getBookingTypeId()
                ),
            ]
            : null,

            default => null,
        };
    }


    /** Resolve dynamic blocked dates for booking type. @param int $bookingTypeId @return array */
    private function resolveBlockedDatesByBookingType(int $bookingTypeId): array
    {
        return SystemReferenceEnum::getBookingRestrictionDates($bookingTypeId) ?? [];
    }

    private function resolveOutUnitFoodBookingRestrictions(SystemReferenceEnum $enum, array $value): ?array
    {
        return match ($enum) {
            SystemReferenceEnum::OUT_UNIT_FOOD_SERVICE_HOURS =>
            $this->field_type_id === FieldTypeEnum::TIME->value
            ? ['service_hours' => $value]
            : null,

            SystemReferenceEnum::OUT_UNIT_FOOD_RESTRICTIONS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,

            SystemReferenceEnum::OUT_UNIT_FOOD_COUNT_BUSINESS_DAYS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['count_businsday' => $value['count_businsday'] ?? null]
            : null,

            SystemReferenceEnum::OUT_UNIT_FOOD_MAX_ADVANCE_DAYS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['max_advance_days' => $value['max_advance_days'] ?? null]
            : null,

            default => null,
        };
    }

    private function resolveCarBookingRestrictionsByFormField(
        FormField $formField,
        SystemReferenceEnum $enum,
        array $value
    ): ?array {
        return match ($enum) {
            SystemReferenceEnum::CAR_SERVICE_HOURS =>
            $formField->field_type_id === FieldTypeEnum::TIME->value
            ? ['service_hours' => $value]
            : null,

            SystemReferenceEnum::CAR_RESTRICTIONS =>
            $formField->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,

            default => null,
        };
    }

    /** Resolve TRANSPORTATION_BOOKING restrictions. */
    private function resolveTransportationBookingRestrictionsByFormField(
        FormField $formField,
        SystemReferenceEnum $enum,
        array $value
    ): ?array {
        return match ($enum) {
            SystemReferenceEnum::TRANSPORTATION_SERVICE_HOURS =>
            $formField->field_type_id === FieldTypeEnum::TIME->value
            ? ['service_hours' => $value]
            : null,

            SystemReferenceEnum::TRANSPORTATION_MINIMUM_ADVANCE_DAYS =>
            $formField->field_type_id === FieldTypeEnum::DATE->value
            ? ['minimum_advance_days' => $value['minimum_advance_days'] ?? null]
            : null,

            SystemReferenceEnum::TRANSPORTATION_RESTRICTION_DATES =>
            $formField->field_type_id === FieldTypeEnum::DATE->value
            ? [
                'blocked_dates' => $this->resolveBlockedDatesByBookingType(
                    BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId()
                ),
            ]
            : null,

            default => null,
        };
    }

    /** Resolve CAR_MAINTENANCE_BOOKING restrictions. */
    private function resolveCarMaintenanceBookingRestrictionsByFormField(
        FormField $formField,
        SystemReferenceEnum $enum,
        array $value
    ): ?array {
        return match ($enum) {
            SystemReferenceEnum::CAR_MAINTENANCE_RESTRICTION_DATES =>
            $formField->field_type_id === FieldTypeEnum::DATE->value
            ? [
                'blocked_dates' => $this->resolveBlockedDatesByBookingType(
                    BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId()
                ),
            ]
            : null,

            default => null,
        };
    }

    /** Resolve food booking restrictions: service hours for time fields, not-permitted hours for date fields. */
    private function resolveFoodBookingRestrictions(SystemReferenceEnum $enum, array $value): ?array
    {
        return match ($enum) {
            SystemReferenceEnum::FOOD_SERVICE_HOURS =>
            $this->field_type_id === FieldTypeEnum::TIME->value
            ? ['service_hours' => $value]
            : null,

            SystemReferenceEnum::FOOD_RESTRICTIONS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,

            default => null,
        };
    }


    /** Resolve IN_UNIT_FOOD_BOOKING restrictions: service hours, booking limit, and multiple_of rule. */
    private function resolveInUnitFoodBookingRestrictions(SystemReferenceEnum $enum, array $value): ?array
    {
        return match ($enum) {
            SystemReferenceEnum::IN_UNIT_FOOD_SERVICE_HOURS =>
            $this->field_type_id === FieldTypeEnum::TIME->value
            ? ['service_hours' => $value]
            : null,

            SystemReferenceEnum::IN_UNIT_FOOD_RESTRICTIONS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,

            SystemReferenceEnum::IN_UNIT_FOOD_COUNT_BUSINESS_DAYS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['count_businsday' => $value['count_businsday'] ?? null]
            : null,

            SystemReferenceEnum::IN_UNIT_FOOD_MAX_ADVANCE_DAYS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['max_advance_days' => $value['max_advance_days'] ?? null]
            : null,

            SystemReferenceEnum::IN_UNIT_FOOD_PEOPLE_AMOUNT =>
            $this->field_type_id === FieldTypeEnum::NUMBER->value
            ? ['multiple_of' => $value['multiple_of'] ?? null]
            : null,

            default => null,
        };
    }

    private function resolveWorkSpaceRestrictions(SystemReferenceEnum $enum, ?array $value): ?array
    {
        return match ($enum) {

            SystemReferenceEnum::LECTURE_ROOM_SERVICE_HOURS =>
            in_array($this->field_type_id, [FieldTypeEnum::TIME->value, FieldTypeEnum::MULTI_SELECT->value])
            ? ['service_hours' => $value]
            : null,
            SystemReferenceEnum::LECTURE_ROOM_RESTRICTIONS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,
            SystemReferenceEnum::LECTURE_ROOM_DURATION_TIME =>
            $this->field_type_id === FieldTypeEnum::TIME->value
            ? ['duration_time' => $value['duration_time'] ?? null]
            : null,

            SystemReferenceEnum::SMALL_COMMNAD_ROOM_SERVICE_HOURS =>
            in_array($this->field_type_id, [FieldTypeEnum::TIME->value, FieldTypeEnum::MULTI_SELECT->value])
            ? ['service_hours' => $value]
            : null,
            SystemReferenceEnum::SMALL_COMMNAD_ROOM_RESTRICTIONS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,
            SystemReferenceEnum::SMALL_COMMNAD_ROOM_DURATION_TIME =>
            $this->field_type_id === FieldTypeEnum::TIME->value
            ? ['duration_time' => $value['duration_time'] ?? null]
            : null,

            SystemReferenceEnum::TECH_HAMAMA_FIRST_SERVICE_HOURS =>
            in_array($this->field_type_id, [FieldTypeEnum::TIME->value, FieldTypeEnum::MULTI_SELECT->value])
            ? ['service_hours' => $value]
            : null,
            SystemReferenceEnum::TECH_HAMAMA_FIRST_RESTRICTIONS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,
            SystemReferenceEnum::TECH_HAMAMA_FIRST_DURATION_TIME =>
            $this->field_type_id === FieldTypeEnum::TIME->value
            ? ['duration_time' => $value['duration_time'] ?? null]
            : null,

            SystemReferenceEnum::TECH_HAMAMA_SECOND_SERVICE_HOURS =>
            in_array($this->field_type_id, [FieldTypeEnum::TIME->value, FieldTypeEnum::MULTI_SELECT->value])
            ? ['service_hours' => $value]
            : null,
            SystemReferenceEnum::TECH_HAMAMA_SECOND_RESTRICTIONS =>
            $this->field_type_id === FieldTypeEnum::DATE->value
            ? ['not_permitted_hours' => $value['bookings_limit_time'] ?? null]
            : null,
            SystemReferenceEnum::TECH_HAMAMA_SECOND_DURATION_TIME =>
            $this->field_type_id === FieldTypeEnum::TIME->value
            ? ['duration_time' => $value['duration_time'] ?? null]
            : null,

            default => null,
        };
    }

    private function resolveEquipmentBookingRestrictions(
        SystemReferenceEnum $enum,
        array $value
    ): ?array {
        return match ($enum) {
            SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_CLOTHING,
            SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_FOOTWEAR,
            SystemReferenceEnum::EQUIPMENT_MALE_QTY_LIMIT_RELATED_PRODUCTS,

            SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_CLOTHING,
            SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_FOOTWEAR,
            SystemReferenceEnum::EQUIPMENT_FEMALE_QTY_LIMIT_RELATED_PRODUCTS
            => [
                'quantity_limit' => [
                    'max' => $value['max'] ?? null,
                ],
            ],

            default => null,
        };
    }


    /** Retrieve and format option fields for a given FormField model - used mainly for child form fields. */
    private function getOptionFieldsByFormField(FormField $formField): mixed
    {
        if (!$formField->relationLoaded('optionField') || $formField->optionField->isEmpty()) {
            return null;
        }

        $gender = request()->input('gender');

        $allowedGenders = $gender
            ? [GenderEnum::NONE->value, $gender]
            : [GenderEnum::NONE->value];

        $topLevelOptions = $formField->optionField
            ->whereNull('option_field_id')
            ->whereIn('gender', $allowedGenders);

        return $topLevelOptions
            ->map(fn($option) => $this->buildOptionFieldTreeByFormField($option, $formField, $allowedGenders))
            ->filter()
            ->values();
    }



    /** Recursively build the tree of option fields with 'category_fields' key. */
    private function buildOptionFieldTreeByFormField($option, FormField $formField, array $allowedGenders = []): ?array
    {

        $optionData = [
            'option_id' => $option->id,
            'uuid' => $option->uuid,
            'option_name' => $option->name,
            'field_type' => [
                'id' =>$option->fieldType?->id,
                'name' =>$option->fieldType?->name,
            ],
            'form_field_id' => $option->form_field_id,
            'has_category' => (bool) ($option->has_category ?? false),
            'is_input' =>(bool) $option->is_input??false,
            'payload_section_id' => $option->payload_section_id,
        ];

        if ($option->option_field_id) {
            $optionData['parent_option_id'] = $option->option_field_id;
        }

        if ($option->category_name) {
            $optionData['category_name'] = $option->category_name;
        }

        if ($option->has_category) {

            $children = $option->childOptionField
                ->whereIn('gender', $allowedGenders);

            $optionData['option_fields'] = $children
                ->map(fn($child) => $this->buildOptionFieldTreeByFormField($child, $formField, $allowedGenders))
                ->filter()
                ->values()
                ->all();
        }

        return $optionData;

    }

}

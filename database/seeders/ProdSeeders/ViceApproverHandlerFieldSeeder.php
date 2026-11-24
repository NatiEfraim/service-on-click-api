<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\PayloadSectionEnum;
use App\Enums\UnitEnum;
use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Database\Seeder;

/**
 * The web rows of the mobile-owned "גורם מטפל" selects on the in-unit food (11) and transportation (8) forms,
 * PINNED to the exact mobile ids (fields 106/107, options 351-358): the b2r booking files carry these raw ids and
 * ImportBookingService maps by id, like every other shared field. The mobile app owns the fields - it decides which
 * options a requester may pick (its option_field_has_users table) - and the web only displays the imported choice,
 * so they are seeded INACTIVE (never on the web forms or the field-management screens) with ACTIVE option rows.
 */
class ViceApproverHandlerFieldSeeder extends Seeder
{
    /** The option names in mobile id order: 351-354 under field 106, 355-358 under field 107. */
    private const OPTION_NAMES = [
        'גורם מטפל - משקים והמטה',
        'גורם מטפל - גבולות וחשיפה',
        'גורם מטפל - תקיפה ואש',
        'גורם מטפל - רובוטיקה ותמרון',
    ];

    public function run(): void
    {
        $fields = [
            106 => ['booking_type' => BookingTypeEnum::IN_UNIT_FOOD_BOOKING, 'field_name' => 'vice_approver_handler_in_unit_food_booking', 'first_option_id' => 351],
            107 => ['booking_type' => BookingTypeEnum::TRANSPORTATION_BOOKING, 'field_name' => 'vice_approver_handler_transportation_booking', 'first_option_id' => 355],
        ];

        foreach ($fields as $fieldId => $definition) {
            $bookingTypeId = $definition['booking_type']->getBookingTypeId();

            if (!$bookingTypeId) {
                continue;
            }

            $formField = $this->pinField($fieldId, $bookingTypeId, $definition['field_name']);

            foreach (self::OPTION_NAMES as $index => $name) {
                $this->pinOption($definition['first_option_id'] + $index, $formField, $name);
            }
        }
    }

    /** Upserts the field at its pinned mobile id; a row in the way means an un-renumbered database - stop loudly. */
    private function pinField(int $fieldId, int $bookingTypeId, string $fieldName): FormField
    {
        $formField = FormField::query()
            ->where('unit_id', UnitEnum::H->value)
            ->where('booking_type_id', $bookingTypeId)
            ->where('field_name', $fieldName)
            ->first();

        $occupant = FormField::query()->find($fieldId);

        if ($occupant && $occupant->field_name !== $fieldName) {
            throw new \RuntimeException(
                "ViceApproverHandlerFieldSeeder: form field id {$fieldId} is taken by '{$occupant->field_name}' - "
                . 'move the web-only fields out of the mobile-shared band first (docs/frontend-field-id-alignment-guide.md).'
            );
        }

        if ($formField && (int) $formField->id !== $fieldId) {
            throw new \RuntimeException(
                "ViceApproverHandlerFieldSeeder: '{$fieldName}' sits at id {$formField->id} but the shared-id contract pins it to {$fieldId} - "
                . 'renumber it first (docs/frontend-field-id-alignment-guide.md).'
            );
        }

        if (!$formField) {
            $formField = new FormField([
                'unit_id' => UnitEnum::H->value,
                'booking_type_id' => $bookingTypeId,
                'field_name' => $fieldName,
            ]);
            $formField->id = $fieldId;
        }

        $formField->fill([
            'field_display_name' => 'גורם מטפל',
            'field_type_id' => FieldTypeEnum::SELECT->value,
            'field_validations' => null,
            'editable' => 0,
            'is_required' => 0,
            'is_input' => 0,
            'payload_section_id' => PayloadSectionEnum::NONE->value,
            // Display-only: never offered on the web forms or the field-management screens.
            'is_active' => 0,
        ])->save();

        return $formField;
    }

    /** Upserts one option at its pinned mobile id, active so the imported choice resolves and displays. */
    private function pinOption(int $optionId, FormField $formField, string $name): void
    {
        $option = OptionField::query()
            ->where('form_field_id', $formField->id)
            ->where('name', $name)
            ->first();

        $occupant = OptionField::query()->find($optionId);

        if ($occupant && ((int) $occupant->form_field_id !== (int) $formField->id || $occupant->name !== $name)) {
            throw new \RuntimeException(
                "ViceApproverHandlerFieldSeeder: option id {$optionId} is taken by '{$occupant->name}' (field {$occupant->form_field_id}) - "
                . 'move the web-only options out of the mobile-shared band first (docs/frontend-field-id-alignment-guide.md).'
            );
        }

        if ($option && (int) $option->id !== $optionId) {
            throw new \RuntimeException(
                "ViceApproverHandlerFieldSeeder: option '{$name}' of field {$formField->id} sits at id {$option->id} but the shared-id contract pins it to {$optionId} - "
                . 'renumber it first (docs/frontend-field-id-alignment-guide.md).'
            );
        }

        if (!$option) {
            $option = new OptionField([
                'form_field_id' => $formField->id,
                'name' => $name,
            ]);
            $option->id = $optionId;
        }

        $option->fill([
            'field_type_id' => FieldTypeEnum::TEXT->value,
            'has_category' => 0,
            'gender' => 0,
            'is_input' => 0,
            'payload_section_id' => PayloadSectionEnum::SELECTS->value,
            'is_active' => 1,
        ])->save();
    }
}

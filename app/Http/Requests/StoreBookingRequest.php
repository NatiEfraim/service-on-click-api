<?php

namespace App\Http\Requests;

use App\Enums\PermissionEnum;
use App\Models\BookingType;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\UnitHasBookingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validates a web-portal booking submission (POST /bookings/{bookingTypeId}).
 *
 * Request shape (multipart/form-data):
 *   values[{form_field_id}] = "text / date / number value"
 *   values[{form_field_id}] = {option_field_id}   // SELECT field
 *   files[]                 = <UploadedFile>       // images or PDF, optional
 */
class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bookingTypeId = (int) $this->route('bookingTypeId');

        // User must hold the user_{id} permission for this type.
        $permissionName = PermissionEnum::USER->keyForId($bookingTypeId);
        if (!Auth::user()->hasPermissionTo($permissionName)) {
            return false;
        }

        // The type must be available in the user's unit.
        return UnitHasBookingType::where('unit_id', Auth::user()->unit_id)
            ->where('booking_type_id', $bookingTypeId)
            ->exists();
    }

    public function rules(): array
    {
        return [
            'values'   => ['nullable', 'array'],
            'values.*' => ['nullable'],
            'files'    => ['nullable', 'array', 'max:10'],
            'files.*'  => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf'],
        ];
    }

    public function messages(): array
    {
        return [
            'files.max'    => 'ניתן לצרף עד 10 קבצים',
            'files.*.max'  => 'גודל קובץ מרבי הוא 10MB',
            'files.*.mimes' => 'סוגי קבצים מותרים: תמונות ו-PDF בלבד',
        ];
    }

    /**
     * After basic validation, verify that:
     * - Every required root-level form field for this type/unit has a value.
     * - SELECT field values are valid option_field_ids for the given field.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $v) {
            $bookingTypeId = (int) $this->route('bookingTypeId');
            $unitId = (int) Auth::user()->unit_id;
            $submitted = $this->input('values', []);

            $rootFields = FormField::where('booking_type_id', $bookingTypeId)
                ->where('unit_id', $unitId)
                ->whereNull('form_field_id')
                ->where('is_active', '!=', false)
                ->get();

            foreach ($rootFields as $field) {
                $fieldId = (string) $field->id;
                $value = $submitted[$fieldId] ?? null;
                $isEmpty = $value === null || $value === '' || $value === [];

                if ($field->is_required && $isEmpty) {
                    $v->errors()->add(
                        "values.{$fieldId}",
                        "שדה \"{$field->field_display_name}\" הוא חובה"
                    );
                    continue;
                }

                // For SELECT fields: value must be a valid option_field_id.
                if (!$isEmpty && $field->field_type_id == \App\Enums\FieldTypeEnum::SELECT->value) {
                    $optionId = (int) $value;
                    $validOption = OptionField::where('id', $optionId)
                        ->where('form_field_id', $field->id)
                        ->where('is_active', true)
                        ->exists();

                    if (!$validOption) {
                        $v->errors()->add(
                            "values.{$fieldId}",
                            "ערך לא תקין עבור שדה \"{$field->field_display_name}\""
                        );
                    }
                }
            }
        });
    }
}

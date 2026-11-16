<?php

namespace App\Http\Requests;

use App\Models\FormField;
use App\Models\OptionField;
use App\Services\RequesterService;
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
        $available = app(RequesterService::class)->availableBookingTypeIds(Auth::user());

        return in_array($bookingTypeId, $available, true);
    }

    public function rules(): array
    {
        return [
            'values'   => ['nullable', 'array'],
            'values.*' => ['nullable'],
            // a choice field whose chosen option asks for a typed value: field id => that value
            'option_values'   => ['nullable', 'array'],
            'option_values.*' => ['nullable', 'string', 'max:255'],
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
            $typed = $this->input('option_values', []);

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
                    $option = OptionField::where('id', $optionId)
                        ->where('form_field_id', $field->id)
                        ->where('is_active', true)
                        ->first();

                    if (!$option) {
                        $v->errors()->add(
                            "values.{$fieldId}",
                            "ערך לא תקין עבור שדה \"{$field->field_display_name}\""
                        );
                        continue;
                    }

                    // a list with sub-choices: one of them must be chosen (the chosen id is the sub-choice's)
                    if (OptionField::where('option_field_id', $option->id)->where('is_active', true)->exists()) {
                        $v->errors()->add("values.{$fieldId}", 'יש לבחור תת-אפשרות');
                        continue;
                    }

                    // a "type in a value" choice: its value is required, and a number when the choice is a number
                    if (\App\Http\Resources\RequesterFormResource::asksForValue($option)) {
                        $text = trim((string) ($typed[$fieldId] ?? ''));
                        if ($text === '') {
                            $v->errors()->add("option_values.{$fieldId}", "יש להזין ערך עבור \"{$option->name}\"");
                        } elseif ((int) $option->field_type_id === \App\Enums\FieldTypeEnum::NUMBER->value && !is_numeric($text)) {
                            $v->errors()->add("option_values.{$fieldId}", 'יש להזין מספר');
                        }
                    }
                }
            }
        });
    }
}

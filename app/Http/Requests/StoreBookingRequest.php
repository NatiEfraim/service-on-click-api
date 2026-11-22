<?php

namespace App\Http\Requests;

use App\Domain\Workflow\Workflow;
use App\Enums\FieldTypeEnum;
use App\Http\Resources\RequesterFormResource;
use App\Models\FormField;
use App\Models\OptionField;
use App\Services\RequesterService;
use App\Support\FieldValueValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validates a web-portal booking submission (POST /bookings/{bookingTypeId}).
 *
 * Request shape (multipart/form-data):
 *   values[{form_field_id}] = "text / date / number value"
 *   values[{form_field_id}] = {option_field_id}   // SELECT field
 *   files[]                 = <UploadedFile>       // images or PDF, optional
 *   comment                 = "הערה"               // optional, saved as the booking's first comment
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
            // the same rule every booking comment follows (the workflow's and the mobile side's)
            'comment'  => ['nullable', 'string', 'max:' . Workflow::COMMENT_MAX_LENGTH, 'regex:' . Workflow::COMMENT_REGEX],
        ];
    }

    public function messages(): array
    {
        return [
            'files.max'    => 'ניתן לצרף עד 10 קבצים',
            'files.*.max'  => 'גודל קובץ מרבי הוא 10MB',
            'files.*.mimes' => 'סוגי קבצים מותרים: תמונות ו-PDF בלבד',
            'comment.max'   => 'ההערה ארוכה מ-' . Workflow::COMMENT_MAX_LENGTH . ' תווים',
            'comment.regex' => 'ההערה יכולה להכיל אותיות בעברית ורווחים בלבד',
        ];
    }

    /**
     * After basic validation, verify that:
     * - No value is sent for a field of the handler section (those are filled during the handling, never on submission).
     * - Every required root-level requester field for this type/unit has a value.
     * - SELECT field values are valid option_field_ids for the given field.
     * - A typed value obeys the field's own rules (form_fields.field_validations) and its type.
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

            foreach ($rootFields->filter(fn (FormField $field): bool => $field->isHandlerField()) as $handlerField) {
                if (array_key_exists((string) $handlerField->id, $submitted) || array_key_exists($handlerField->id, $submitted)) {
                    $v->errors()->add("values.{$handlerField->id}", 'שדה זה אינו ניתן למילוי בשלב ההגשה');
                }
            }

            foreach ($rootFields->reject(fn (FormField $field): bool => $field->isHandlerField()) as $field) {
                $fieldId = (string) $field->id;
                $value = $submitted[$fieldId] ?? null;
                $isEmpty = $value === null || $value === '' || $value === [];

                // A field that depends on another one (the printer IP on "מדפסת מחוברת") counts only while the condition holds:
                // otherwise it is neither required nor checked.
                if (is_array($field->depends_on) && isset($field->depends_on['field'])) {
                    $parent = $rootFields->firstWhere('field_name', $field->depends_on['field']);
                    $parentValue = $parent ? (string) ($submitted[(string) $parent->id] ?? '') : '';
                    // A select sends the option's id; the condition names the option.
                    $parentLabel = $parentValue !== '' ? (OptionField::query()->whereKey($parentValue)->value('name') ?? $parentValue) : '';

                    if (!in_array($parentLabel, (array) ($field->depends_on['values'] ?? []), true)) {
                        continue;
                    }
                }

                if ($field->is_required && $isEmpty) {
                    $v->errors()->add("values.{$fieldId}", FieldValueValidator::requiredMessage($field));
                    continue;
                }

                if ($isEmpty) {
                    continue;
                }

                // For SELECT fields: value must be a valid option_field_id.
                if ($field->field_type_id == FieldTypeEnum::SELECT->value) {
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
                    if (RequesterFormResource::asksForValue($option)) {
                        $text = trim((string) ($typed[$fieldId] ?? ''));
                        if ($text === '') {
                            $v->errors()->add("option_values.{$fieldId}", "יש להזין ערך עבור \"{$option->name}\"");
                        } elseif ((int) $option->field_type_id === FieldTypeEnum::NUMBER->value && !is_numeric($text)) {
                            $v->errors()->add("option_values.{$fieldId}", 'יש להזין מספר');
                        }
                    }

                    continue;
                }

                // A typed value: the field's own rules (regex, max...) and its type.
                foreach (FieldValueValidator::errorsFor($field, $value) as $error) {
                    $v->errors()->add("values.{$fieldId}", $error);
                }
            }
        });
    }
}

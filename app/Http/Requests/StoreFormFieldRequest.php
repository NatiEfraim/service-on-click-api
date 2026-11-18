<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A new field at the end of the form; FormFieldRules re-checks name, type and options. */
class StoreFormFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field_display_name' => ['required', 'string', 'max:100'],
            'field_type_id' => ['required', 'integer', 'exists:field_types,id'],
            'is_required' => ['sometimes', 'boolean'],
            'editable' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'nullable', 'array', 'max:100'],
            'options.*' => ['nullable', 'string', 'max:100'],
            // Route forms: who fills the field and its width on the 4-column grid.
            'section' => ['sometimes', 'in:requester,handler'],
            'col_span' => ['sometimes', 'integer', 'in:1,2,4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $generic = 'אחד או יותר מהנתונים שנשלחו אינו תקין.';

        return [
            'field_display_name.required' => 'שם השדה חייב להכיל בין 2 ל-50 תווים',
            'field_display_name.string' => 'שם השדה חייב להכיל בין 2 ל-50 תווים',
            'field_display_name.max' => 'שם השדה חייב להכיל בין 2 ל-50 תווים',
            'field_type_id.required' => 'סוג השדה אינו נתמך',
            'field_type_id.integer' => 'סוג השדה אינו נתמך',
            'field_type_id.exists' => 'סוג השדה אינו נתמך',
            'is_required.boolean' => $generic,
            'editable.boolean' => $generic,
            'options.array' => $generic,
            'options.max' => $generic,
            'options.*.string' => $generic,
            'options.*.max' => $generic,
            'section.in' => $generic,
            'col_span.integer' => $generic,
            'col_span.in' => $generic,
        ];
    }
}

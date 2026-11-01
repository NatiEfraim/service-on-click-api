<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Send the version the screen saw and only what changes. */
class UpdateFormFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'field_display_name' => ['sometimes', 'string', 'max:100'],
            'field_type_id' => ['sometimes', 'integer', 'exists:field_types,id'],
            'is_required' => ['sometimes', 'boolean'],
            'editable' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $generic = 'אחד או יותר מהנתונים שנשלחו אינו תקין.';

        return [
            'version.required' => $generic,
            'version.integer' => $generic,
            'version.min' => $generic,
            'field_display_name.string' => 'שם השדה חייב להכיל בין 2 ל-50 תווים',
            'field_display_name.max' => 'שם השדה חייב להכיל בין 2 ל-50 תווים',
            'field_type_id.integer' => 'סוג השדה אינו נתמך',
            'field_type_id.exists' => 'סוג השדה אינו נתמך',
            'is_required.boolean' => $generic,
            'editable.boolean' => $generic,
        ];
    }
}

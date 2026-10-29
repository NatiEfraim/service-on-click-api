<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The query of GET /api/test-import: which of the imported bookings to show. */
class TestImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'booking_type_id' => ['nullable', 'integer', 'exists:booking_types,id'],
            'serial_number' => ['nullable', 'digits_between:1,18'],
        ];
    }

    public function messages(): array
    {
        return [
            'page.integer' => 'מספר העמוד אינו תקין',
            'page.min' => 'מספר העמוד אינו תקין',
            'per_page.integer' => 'מספר ההזמנות בעמוד חייב להיות בין 1 ל-50',
            'per_page.min' => 'מספר ההזמנות בעמוד חייב להיות בין 1 ל-50',
            'per_page.max' => 'מספר ההזמנות בעמוד חייב להיות בין 1 ל-50',
            'booking_type_id.integer' => 'סוג ההזמנה אינו תקין',
            'booking_type_id.exists' => 'סוג ההזמנה שנבחר אינו קיים',
            'serial_number.digits_between' => 'המספר הסידורי אינו תקין',
        ];
    }
}

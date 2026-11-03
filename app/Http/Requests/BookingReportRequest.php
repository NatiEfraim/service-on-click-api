<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The filters of the bookings report: the query string of the list, the body (or the query string) of the Excel export. All optional; they combine. */
class BookingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'booking_type_id' => ['nullable', 'integer', 'exists:booking_types,id'],
            'status_id' => ['nullable', 'integer', 'exists:statuses,id'],
            // Days (Y-m-d) on which the booking was created, both ends included.
            'from_created_at' => ['nullable', 'date_format:Y-m-d'],
            'to_created_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_created_at'],
            // Used by the list only.
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'booking_type_id.integer' => 'תחום הקריאה שנבחר אינו תקין.',
            'booking_type_id.exists' => 'תחום הקריאה שנבחר אינו קיים.',
            'status_id.integer' => 'הסטטוס שנבחר אינו תקין.',
            'status_id.exists' => 'הסטטוס שנבחר אינו קיים.',
            'from_created_at.date_format' => 'תאריך ההתחלה חייב להיות בתבנית שנה-חודש-יום, למשל 2026-11-03.',
            'to_created_at.date_format' => 'תאריך הסיום חייב להיות בתבנית שנה-חודש-יום, למשל 2026-11-03.',
            'to_created_at.after_or_equal' => 'תאריך הסיום חייב להיות באותו יום של תאריך ההתחלה או אחריו.',
            'page.integer' => 'מספר העמוד אינו תקין.',
            'page.min' => 'מספר העמוד אינו תקין.',
            'per_page.integer' => 'מספר השורות בעמוד אינו תקין.',
            'per_page.min' => 'מספר השורות בעמוד אינו תקין.',
        ];
    }
}

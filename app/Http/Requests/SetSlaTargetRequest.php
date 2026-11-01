<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/bookings-admin-management/targets - set the handling target of a booking type, or remove it with target_hours = null. */
class SetSlaTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'booking_type_id' => ['required', 'integer', 'exists:booking_types,id'],
            // Hours from the booking's opening to its closing; null removes the target. The range is checked by SlaTargetService, which says what it is.
            'target_hours' => ['present', 'nullable', 'numeric'],
            'warn_ratio' => ['nullable', 'numeric'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'booking_type_id.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'booking_type_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'booking_type_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'target_hours.present' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'target_hours.numeric' => 'יעד הטיפול חייב להיות מספר שעות.',
            'warn_ratio.numeric' => 'סף ההתראה המוקדמת חייב להיות מספר בין 0 ל-1.',
        ];
    }
}

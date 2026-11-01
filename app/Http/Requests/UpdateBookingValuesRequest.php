<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/bookings-approver-management/bookings/{serial}/values. The shape only: which fields may be changed now, and whether each new
 * value is valid, are decided by BookingValueService.
 */
class UpdateBookingValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // form_field_id => the new value, e.g. {"2": "2026-11-20", "3": "09:30"}
            'values' => ['required', 'array', 'min:1', 'max:20'],
            'values.*' => ['required', 'string', 'max:20'],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'values.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'values.array' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'values.min' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'values.max' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'values.*.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'values.*.string' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'values.*.max' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.min' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'reason.max' => 'הסיבה ארוכה מדי.',
        ];
    }
}

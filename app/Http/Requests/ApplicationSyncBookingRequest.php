<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Filters for the application-sync bookings list; all optional. */
class ApplicationSyncBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'booking_type_id'      => ['nullable', 'integer', 'exists:booking_types,id'],
            'status_id'            => ['nullable', 'integer', 'exists:statuses,id'],
            'serial_number'        => ['nullable', 'string', 'max:30'],
            'personal_number'      => ['nullable', 'string', 'max:30'],
            'personal_id'          => ['nullable', 'string', 'max:30'],
            'from_created_at'      => ['nullable', 'date_format:Y-m-d'],
            'to_created_at'        => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_created_at'],
            // true = fully synced, false = pending, absent = all.
            'sent_to_application'  => ['nullable', 'boolean'],
            'page'                 => ['nullable', 'integer', 'min:1'],
            'per_page'             => ['nullable', 'integer', 'min:1'],
        ];
    }
}

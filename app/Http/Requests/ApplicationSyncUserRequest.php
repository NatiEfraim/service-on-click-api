<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Filters for the application-sync users list; all optional. */
class ApplicationSyncUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name'           => ['nullable', 'string', 'max:100'],
            'personal_number'     => ['nullable', 'string', 'max:30'],
            'personal_id'         => ['nullable', 'string', 'max:30'],
            'unit_id'             => ['nullable', 'integer', 'exists:units,id'],
            // true = role exported to mobile, false = pending, absent = all.
            'sent_to_application' => ['nullable', 'boolean'],
            'page'                => ['nullable', 'integer', 'min:1'],
            'per_page'            => ['nullable', 'integer', 'min:1'],
        ];
    }
}

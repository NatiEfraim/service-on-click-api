<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shape only: who may assign whom, and in which statuses, belongs to BookingAssignmentService. */
class SetBookingHandlerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The uuid of the person who takes the request, or null to clear the assignment.
            'handler_uuid' => ['present', 'nullable', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $generic = 'אחד או יותר מהנתונים שנשלחו אינו תקין.';

        return [
            'handler_uuid.present' => $generic,
            'handler_uuid.uuid' => $generic,
            'version.required' => $generic,
            'version.integer' => $generic,
            'version.min' => $generic,
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shape only: whether the move is allowed, and the comment rules, belong to BookingWorkflowService. */
class ChangeBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The Idempotency-Key header is validated like a field. */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'integer'],
            // A change is refused when the booking moved on since the user saw this version.
            'version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status_id.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'status_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.min' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'comment.string' => 'ההערה אינה תקינה.',
            'comment.max' => 'ההערה ארוכה מדי.',
            'idempotency_key.max' => 'מפתח הפעולה ארוך מדי.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\SyncConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Which status stands, and why. */
class ResolveSyncConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // accept_external: the mobile app's status stands. keep_internal: our decision stands and is sent again.
            'resolution' => ['required', Rule::in([SyncConflict::ACCEPT_EXTERNAL, SyncConflict::KEEP_INTERNAL])],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'resolution.required' => 'החלטה לא מוכרת',
            'resolution.in' => 'החלטה לא מוכרת',
            'reason.required' => 'יש לנמק את ההחלטה',
            'reason.string' => 'יש לנמק את ההחלטה',
            'reason.max' => 'הנימוק ארוך מדי',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** department_id, or null to take the person out of any department. */
class SetUserDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'department_id' => ['present', 'nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'department_id.present' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'department_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
        ];
    }
}

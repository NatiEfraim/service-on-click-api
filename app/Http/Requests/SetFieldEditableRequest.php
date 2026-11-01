<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Whether an approver may correct this field's value; the version is the one the screen saw. */
class SetFieldEditableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'editable' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'editable.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'editable.boolean' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'version.min' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
        ];
    }
}

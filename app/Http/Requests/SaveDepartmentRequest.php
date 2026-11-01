<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Create (name required) and update (name and/or is_active) of a department. */
class SaveDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'השם חייב להכיל בין 2 ל-40 תווים',
            'name.string' => 'השם חייב להכיל בין 2 ל-40 תווים',
            'name.max' => 'השם חייב להכיל בין 2 ל-40 תווים',
            'is_active.boolean' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
        ];
    }
}

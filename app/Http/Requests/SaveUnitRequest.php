<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The unit's code (its letter, immutable) and the name people see here. */
class SaveUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // OrgRules decides what a code or name may look like, with the reason in Hebrew.
        return [
            'code' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'האות של היחידה חייבת להיות 1 עד 3 אותיות באנגלית',
            'code.string' => 'האות של היחידה חייבת להיות 1 עד 3 אותיות באנגלית',
            'code.max' => 'האות של היחידה חייבת להיות 1 עד 3 אותיות באנגלית',
            'name.required' => 'השם חייב להכיל בין 2 ל-40 תווים',
            'name.string' => 'השם חייב להכיל בין 2 ל-40 תווים',
            'name.max' => 'השם חייב להכיל בין 2 ל-40 תווים',
        ];
    }
}

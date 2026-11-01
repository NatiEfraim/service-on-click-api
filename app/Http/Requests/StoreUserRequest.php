<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Only a super admin chooses the unit; everyone else creates users in their own unit. */
    protected function prepareForValidation(): void
    {
        if (!Auth::user()?->hasRole('super_admin')) {
            $this->merge(['unit_id' => Auth::user()?->unit_id]);
        }

        $this->merge([
            'personal_number' => trim((string) $this->input('personal_number')),
            'full_name' => trim((string) $this->input('full_name')),
            'phone' => trim((string) $this->input('phone')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'personal_number' => ['required', 'regex:/^\d{7,8}$/', 'unique:users,personal_number'],
            'full_name' => ['required', 'max:30', "regex:/^[א-ת'\-\s]+$/u"],
            'phone' => ['nullable', 'regex:/^05\d{8}$/'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            // Optional: an active department of the unit the person is created in.
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query->where('unit_id', $this->input('unit_id'))->where('is_active', true)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'personal_number.required' => 'מספר אישי אינו תקין',
            'personal_number.regex' => 'מספר אישי אינו תקין',
            'personal_number.unique' => 'משתמש עם מספר אישי זה כבר קיים',
            'full_name.required' => 'שם מלא הוא שדה חובה ואינו יכול לעלות על 30 תווים',
            'full_name.max' => 'שם מלא הוא שדה חובה ואינו יכול לעלות על 30 תווים',
            'full_name.regex' => 'שם מלא יכול להכיל אותיות בעברית בלבד',
            'phone.regex' => 'מספר טלפון אינו תקין - יש להזין 10 ספרות המתחילות ב-05',
            'unit_id.required' => 'יש לבחור יחידה',
            'unit_id.integer' => 'יש לבחור יחידה',
            'unit_id.exists' => 'היחידה שנבחרה אינה קיימת',
            'department_id.integer' => 'המחלקה אינה שייכת ליחידה שנבחרה',
            'department_id.exists' => 'המחלקה אינה שייכת ליחידה שנבחרה',
        ];
    }
}

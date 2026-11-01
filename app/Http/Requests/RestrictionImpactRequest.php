<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The full list of blocked dates the setting would hold (may be empty). */
class RestrictionImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dates' => ['present', 'array', 'max:400'],
            'dates.*' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dates.present' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'dates.array' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'dates.max' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'dates.*.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'dates.*.date_format' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
        ];
    }
}

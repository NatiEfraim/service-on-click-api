<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Ids of ALL the stand-alone fields of the form, in the new order. */
class ReorderFormFieldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $generic = 'אחד או יותר מהנתונים שנשלחו אינו תקין.';

        return [
            'ids.required' => $generic,
            'ids.array' => $generic,
            'ids.min' => $generic,
            'ids.max' => $generic,
            'ids.*.required' => $generic,
            'ids.*.integer' => $generic,
            'ids.*.distinct' => $generic,
        ];
    }
}

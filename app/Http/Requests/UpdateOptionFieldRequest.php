<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;


class UpdateOptionFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }


    protected function prepareForValidation(): void
    {
        $formFieldId = (int) $this->route('formFieldId');

        $this->merge([
            'form_field_id' => $formFieldId,
        ]);

        if ($this->has('is_input')) {
            $this->merge([
                'is_input' => filter_var(
                    $this->input('is_input'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
            ]);
        }
    }

    public function rules(): array
    {


        return [
            'form_field_id' => ['required', 'integer', 'exists:form_fields,id'],
            'option_id' => ['nullable', 'integer', 'exists:option_fields,id'],
            'option_name' => ['required', 'string', 'min:1', 'max:50', 'regex:/^[a-zA-Z0-9א-ת\s"\',\.\-()]+$/u'],
            'parent_option_id' => ['nullable', 'integer', 'exists:option_fields,id'],
            'has_category' => ['nullable', 'boolean'],
            'field_type_id' => ['nullable', 'integer', 'exists:field_types,id'],
            'is_input' => ['nullable', 'boolean'],
        ];

    }


    public function messages(): array
    {
        return [

            'form_field_id.required' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'form_field_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'form_field_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',

            'option_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'option_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',

            'option_name.required' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'option_name.string' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'option_name.min' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'option_name.max' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'option_name.regex' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',

            'parent_option_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'parent_option_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',

            'has_category.boolean' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',

            'field_type_id.required' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'field_type_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
            'field_type_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',

            'is_input.boolean' => 'אחד או יותר מהנתונים שנשלחו אינם תקינים',
        ];

    }


    private function validateSelectOptionsRecursive(int $formFieldId, array $data, array $multySelectedOptions = []): bool
    {

        
        foreach ($data as $option) {
           
            if (
                !(array_key_exists('has_category', $option))
                || (!array_key_exists('option_id', $option) && !array_key_exists('option_name', $option))
                || (array_key_exists('has_category', $option) && !is_array($option['option_fields']))
            ) {
                return false;
            }

            if (array_key_exists('option_name', $option) &&(!is_string($option['option_name']) || trim($option['option_name']) === '')) {
                return false;
            }

            $hasCategory = (int) $option['has_category'];
            if ($hasCategory !== 0 && $hasCategory !== 1) {
                return false;
            }

            if ($hasCategory === 1 && count($option['option_fields']) === 0) {
                return false;
            }

            if ($hasCategory === 0 && count($option['option_fields']) > 0) {
                return false;
            }

            if (!empty($option['option_fields'])) {
                $isValidChildren = $this->validateSelectOptionsRecursive($formFieldId, $option['option_fields']);
                if (!$isValidChildren) {
                    return false;
                }
            }
        }

        return true;
    }
}

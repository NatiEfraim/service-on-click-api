<?php

namespace App\Http\Requests;


use Illuminate\Foundation\Http\FormRequest;
use App\Models\OptionField;



class RemoveOptionFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {

        return [
            'option_id' => ['nullable', 'integer', 'exists:option_fields,id'],
        ];
 
    }



    public function messages(): array
    {
        return [
            'option_fields.required' => 'יש לספק ערכים',
            'option_fields.array' => 'אחד או יותר מהנתונים שנשלחו אינו תקין',
            'option_fields.*.option_id.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין',
            'option_fields.*.option_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין',
            'option_fields.*.option_id.distinct' => 'אחד או יותר מהנתונים שנשלחו אינו תקין',
            'option_fields.*.option_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינו תקין',
        ];
    }

    public function validateOptionFieldIds(array $optionFields,int $formFieldId): bool
    {
        foreach ($optionFields as $field) {
            if (!isset($field['option_id'])) {
                return false;
            }

            $exists = OptionField::where('id', $field['option_id'])
            ->where('form_field_id',$formFieldId)
            ->exists();

            if (!$exists) {
                return false; 
            }
        }

        return true; 
    }

}

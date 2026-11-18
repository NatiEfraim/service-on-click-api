<?php

namespace App\Http\Requests;

use App\Enums\HttpStatusCodeEnum;

use App\Enums\FieldTypeEnum;
use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UpdateFormFieldOptionRequest extends FormRequest
{

 

    public function __construct()
    {

    }
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {

        try {

            $formFieldId = $this->route('formFieldId');

            if (!$formFieldId || !FormField::find($formFieldId)) {
                throw ValidationException::withMessages(['message' => 'אחד או יותר מהשדות שנשלחו אינם תקינים']);
            }
        
            $rules=[
                'option_fields' => ['required', 'array'],
                'option_fields.*.option_id' => ['nullable', 'integer', 'exists:option_fields,id'],
                'option_fields.*.option_name' => ['nullable', 'string', 'min:1', 'max:50', 'regex:/^[a-zA-Z0-9א-ת\s"\',\.\-()]+$/u'],
                'option_fields.*.has_category' => ['nullable', 'boolean'],
                'option_fields.*.parent_option_id' => ['nullable', 'integer', 'exists:option_fields,id'],
                // The stable code of a root option (option_fields.code): sent = set or cleared, absent = kept.
                'option_fields.*.code' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_\-]+$/'],
            ];
           
            $optionFields = $this->input('option_fields');
            $multiSelectedOptions=[
                FieldTypeEnum::SELECT->value,
                FieldTypeEnum::RADIO->value,
                FieldTypeEnum::CHECKBOX->value,
                FieldTypeEnum::MULTI_SELECT->value,
            ];
         
            $isValid=  $this->validateSelectOptionsRecursive(formFieldId: $formFieldId,data: $optionFields,multySelectedOptions: $multiSelectedOptions);
            
            if (!$isValid) {
                throw ValidationException::withMessages(['message' => 'ערכי הטופס אינם תקינים']);
            }
     

            return $rules;

        } catch (ValidationException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            app(\App\Services\StorageService::class)->logToS3();
            throw ValidationException::withMessages(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()])->status(Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function messages(): array
    {
        return [

            'option_fields.required' => 'אחד או יותר מהשדות שנשלחו אינם תקינים.',
            'option_fields.array' => 'אירעה שגיאה בשדות האפשרויות.',
            'option_fields.*.option_id.integer' => 'שדה לא תקין.',
            'option_fields.*.option_id.exists' => 'שדה לא קיים במערכת.',
            'option_fields.*.option_name.required' => 'נא להזין ערך.',
            'option_fields.*.option_name.string' => 'הערך שהוזן אינו תקין.',
            'option_fields.*.option_name.min' => 'ערך קצר מדי.',
            'option_fields.*.option_name.max' => 'ערך ארוך מדי.',
            'option_fields.*.option_name.regex' => 'הערך שהוזן מכיל תווים לא תקינים.',
            'option_fields.*.has_category.boolean' => 'ערך לא תקין עבור קטגוריה.',
            'option_fields.*.parent_option_id.integer' => 'שדה לא תקין.',
            'option_fields.*.parent_option_id.exists' => 'הקשר בין שדות אינו תקין.',
            'option_fields.*.code.string' => 'קוד האפשרות יכול להכיל אותיות באנגלית, ספרות, קו תחתון ומקף בלבד (עד 50 תווים)',
            'option_fields.*.code.max' => 'קוד האפשרות יכול להכיל אותיות באנגלית, ספרות, קו תחתון ומקף בלבד (עד 50 תווים)',
            'option_fields.*.code.regex' => 'קוד האפשרות יכול להכיל אותיות באנגלית, ספרות, קו תחתון ומקף בלבד (עד 50 תווים)',
        ];
    }

    /** Validates select options recursively. */
    private function validateSelectOptionsRecursive(int $formFieldId, array $data, array $multySelectedOptions = []): bool
    {
       

        foreach ($data as $key => $value) {
        
            if (!isset($value['has_category'])) {
                return false;
            }

            if (!isset($value['option_fields']) && ($value['has_category'] == false)) {

                if (!isset($value['option_name'])) {
                   return false;
                }
                return true;
            }

            if (isset($value['option_id'])) {

                $optionField = OptionField::where('id', $value['option_id'])
                    ->where('form_field_id', $formFieldId)
                    ->first();

                if (!$optionField) {
                    return false;
                }

                if (in_array($optionField->field_type_id, $multySelectedOptions) && $value['has_category'] == true) {

                    if (!isset($value['option_fields']) || (!is_array($value['option_fields']))) {
                        return false;
                    }

                    $valid = $this->validateSelectOptionsRecursive(
                        formFieldId: $formFieldId,
                        data: $value['option_fields'],
                        multySelectedOptions: $multySelectedOptions
                    );

                    if (!$valid) {
                        return false;
                    }
                } 
            }else if ($value['has_category'] == true) {
                if (isset($value['option_fields']) && !is_array($value['option_fields'])) {
                   return false;
                }
                $valid = $this->validateSelectOptionsRecursive(
                    formFieldId: $formFieldId,
                    data: $value['option_fields'],
                    multySelectedOptions: $multySelectedOptions
                );
                if (!$valid) {
                    return false;
                }
            } 

           
        }
        return true;
    }

    private const NAME_REGEX = '/^[a-zA-Z0-9א-ת\s"\',\.\-()]+$/u';

}

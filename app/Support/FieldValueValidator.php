<?php

namespace App\Support;

use App\Enums\FieldTypeEnum;
use App\Models\FormField;
use Illuminate\Support\Facades\Validator;

/**
 * Checks one submitted value against its form field: the field's own rules (form_fields.field_validations, "rule => Hebrew
 * message") and a type sanity rule. Used by the web submission and by the handler fields of a route type. Lists and uploads are
 * not checked here (the select-family and the files have their own checks).
 */
final class FieldValueValidator
{
    private const GENERIC = 'אחד או יותר מהנתונים שנשלחו אינו תקין.';

    /** Rules a field may carry that are applied as they are; anything else in the column is ignored (it is a message map, not code). */
    private const KNOWN_RULES = [
        'string', 'max', 'min', 'regex', 'date', 'date_format', 'numeric', 'integer', 'digits', 'digits_between', 'email', 'url',
        'accepted', 'boolean', 'in', 'not_in', 'size', 'between', 'alpha', 'alpha_num', 'alpha_dash', 'after', 'before', 'after_or_equal', 'before_or_equal',
    ];

    /** Field types whose value is a typed scalar (the ones this validator judges). */
    private const SCALAR_TYPES = [
        FieldTypeEnum::TEXT, FieldTypeEnum::TEXT_BOX, FieldTypeEnum::TEXT_AREA, FieldTypeEnum::TEL, FieldTypeEnum::EMAIL, FieldTypeEnum::URL,
        FieldTypeEnum::NUMBER, FieldTypeEnum::NUMBER_ASTERISK, FieldTypeEnum::RANGE, FieldTypeEnum::DATE, FieldTypeEnum::TIME, FieldTypeEnum::CHECKBOX,
    ];

    /** @return array<int, string> Hebrew errors; empty when the value is fine (or when the field is not a scalar kind) */
    public static function errorsFor(FormField $field, mixed $value): array
    {
        $type = FieldTypeEnum::tryFrom((int) $field->field_type_id);

        if ($type === null || !in_array($type, self::SCALAR_TYPES, true) || !is_scalar($value)) {
            return [];
        }

        $rules = [];
        $messages = [];

        foreach ($field->normalizedValidations() ?? [] as $rule => $message) {
            $name = strtolower(trim(explode(':', $rule, 2)[0]));

            if (!in_array($name, self::KNOWN_RULES, true)) {
                continue;
            }

            $rules[] = $rule;
            $messages['value.' . $name] = $message !== '' ? $message : self::GENERIC;
        }

        // Type sanity, independent of the field's own rules.
        switch ($type) {
            case FieldTypeEnum::DATE:
                $rules[] = 'date_format:Y-m-d';
                $messages['value.date_format'] ??= 'תאריך אינו תקין';
                break;
            case FieldTypeEnum::TIME:
                $rules[] = 'date_format:H:i';
                $messages['value.date_format'] ??= 'שעה אינה תקינה (HH:MM)';
                break;
            case FieldTypeEnum::NUMBER:
            case FieldTypeEnum::NUMBER_ASTERISK:
            case FieldTypeEnum::RANGE:
                $rules[] = 'numeric';
                $messages['value.numeric'] ??= 'יש להזין מספר';
                break;
            case FieldTypeEnum::CHECKBOX:
                // A declaration checkbox (no options): ticked or not.
                $rules[] = 'in:0,1,true,false';
                $messages['value.in'] ??= self::GENERIC;
                break;
            default:
                $rules[] = 'string';
                $messages['value.string'] ??= 'פורמט אינו תקין';
        }

        $validator = Validator::make(['value' => (string) $value], ['value' => array_values(array_unique($rules))], $messages);

        return $validator->fails() ? array_values(array_unique($validator->errors()->get('value'))) : [];
    }

    /** The field's own message for its "required" rule, or a default. */
    public static function requiredMessage(FormField $field): string
    {
        $message = $field->normalizedValidations()['required'] ?? null;

        return is_string($message) && $message !== '' ? $message : "שדה \"{$field->field_display_name}\" הוא חובה";
    }
}

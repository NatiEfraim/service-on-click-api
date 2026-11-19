<?php

namespace App\Http\Requests;

use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Support\PermissionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignAdminRoleRequest extends FormRequest
{
    private const PHONE_REGEX = '#^(?:0(?:5[0-9]{8}|2[0-9]{7}|3[0-9]{7}|4[0-9]{7}|8[0-9]{7}|9[0-9]{7}|7[2-9][0-9]{7}))$#';

    /** 7 to 8 digits, as returned by the ADFS login. */
    private const PERSONAL_NUMBER_REGEX = '/^\d{7,8}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'uuid' => 'required_without:personal_number|nullable|uuid|exists:users,uuid',
            'full_name' => 'required_without:uuid|nullable|string|max:255',
            'personal_number' => ['required_without:uuid', 'nullable', 'string', 'regex:' . self::PERSONAL_NUMBER_REGEX],
            'phone' => ['nullable', 'string', 'regex:' . self::PHONE_REGEX],
            'unit_id' => [
                'required_without:uuid',
                'nullable',
                'integer',
                Rule::in(array_column(UnitEnum::cases(), 'value')),
                'exists:units,id',
            ],
            // Optional; kept in the audit log.
            'reason' => 'nullable|string|max:500',
            'role_assignments' => 'required|array|min:1',
            'role_assignments.*.booking_type_id' => 'required|integer|exists:booking_types,id',
            'role_assignments.*.is_admin' => 'required|boolean',
        ];
    }




    public function messages(): array
    {
        return [
            'uuid.required_without' => 'מזהה המשתמש נדרש.',
            'uuid.uuid' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'uuid.exists' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'full_name.required_without' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'full_name.string' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'full_name.max' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'personal_number.required_without' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'personal_number.regex' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'phone.regex' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'unit_id.required_without' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'unit_id.integer' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'unit_id.in' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'unit_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.array' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.booking_type_id.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.booking_type_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.is_admin.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.is_admin.boolean' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
        ];
    }

    /**
     * The admin role cannot be GRANTED on a type that does not have it (the clearance grant and the infosec / ICT types have their own roles
     * and no admin_{id}): refused like any other flag rule, so a stray permission can never be written here.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('role_assignments', []) as $index => $assignment) {
                if (is_array($assignment)
                    && filter_var($assignment['is_admin'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true
                    && !app(PermissionRules::class)->hasLink(RoleEnum::ADMIN->value, (int) ($assignment['booking_type_id'] ?? 0))) {
                    $validator->errors()->add("role_assignments.{$index}.is_admin", 'אחד או יותר מהנתונים שנשלחו אינו תקין.');
                }
            }
        });
    }

}

<?php

namespace App\Http\Requests;

use App\Models\BookingTypeHasRole;
use Illuminate\Foundation\Http\FormRequest;


class AssignApproverRoleRequest extends FormRequest
{
    /** Israeli phone number format. */
    private const PHONE_REGEX = '#^(?:0(?:5[0-9]{8}|2[0-9]{7}|3[0-9]{7}|4[0-9]{7}|8[0-9]{7}|9[0-9]{7}|7[2-9][0-9]{7}))$#';

    /** Personal number format - 7 to 8 digits, the number the ADFS login returns. */
    private const PERSONAL_NUMBER_REGEX = '/^\d{7,8}$/';

    /** Determine if the user is authorized to make this request. @return bool */
    public function authorize(): bool
    {
        return true;
    }

    /** Base rules plus an optional boolean per approver role; needs uuid or full_name/personal_number. @return array */
    public function rules(): array
    {
        $rules = [
            'uuid' => 'required_without:personal_number|nullable|uuid|exists:users,uuid',
            'full_name' => 'required_without:uuid|nullable|string|max:255',
            'personal_number' => ['required_without:uuid', 'nullable', 'string', 'regex:' . self::PERSONAL_NUMBER_REGEX],
            'phone' => ['nullable', 'string', 'regex:' . self::PHONE_REGEX],
            'role_assignments' => 'required|array|min:1',
            'role_assignments.*.booking_type_id' => 'required|integer|exists:booking_types,id',
            'role_assignments.*.is_approver' => 'required|boolean',
            'role_assignments.*.is_vice_approver' => 'required|boolean',
        ];

        foreach (BookingTypeHasRole::approverManagementRoleEnums() as $roleEnum) {
            $flagRuleKey = 'role_assignments.*.is_' . $roleEnum->toSpatieRoleName();

            $rules[$flagRuleKey] ??= 'sometimes|boolean';
        }

        return $rules;
    }

    /** Custom error messages for validation. @return array */
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
            'role_assignments.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.array' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.booking_type_id.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.booking_type_id.exists' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.is_approver.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.is_vice_approver.required' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.is_approver.boolean' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
            'role_assignments.*.is_vice_approver.boolean' => 'אחד או יותר מהנתונים שנשלחו אינו תקין.',
        ];
    }

}

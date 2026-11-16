<?php

namespace App\Http\Requests;

use App\Enums\RoleEnum;
use App\Models\BookingTypeHasRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;


class AssignApproverRoleRequest extends FormRequest
{
    private const PHONE_REGEX = '#^(?:0(?:5[0-9]{8}|2[0-9]{7}|3[0-9]{7}|4[0-9]{7}|8[0-9]{7}|9[0-9]{7}|7[2-9][0-9]{7}))$#';

    /** 7 to 8 digits, as returned by the ADFS login. */
    private const PERSONAL_NUMBER_REGEX = '/^\d{7,8}$/';

    /**
     * Roles whose flag must be answered (true / false) for every booking type that has the role. The control officer's flag
     * stays optional, as it always was: the transport screens send it only when the column is edited.
     * @var array<int, RoleEnum>
     */
    private const ANSWERED_ROLES = [
        RoleEnum::APPROVER,
        RoleEnum::VICE_APPROVER,
        RoleEnum::INFOSEC_OFFICER,
        RoleEnum::ICT_HANDLER,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** Needs a uuid or full_name/personal_number, plus an optional boolean per approver role. @return array */
    public function rules(): array
    {
        $rules = [
            'uuid' => 'required_without:personal_number|nullable|uuid|exists:users,uuid',
            'full_name' => 'required_without:uuid|nullable|string|max:255',
            'personal_number' => ['required_without:uuid', 'nullable', 'string', 'regex:' . self::PERSONAL_NUMBER_REGEX],
            'phone' => ['nullable', 'string', 'regex:' . self::PHONE_REGEX],
            // Optional; kept in the audit log.
            'reason' => 'nullable|string|max:500',
            'role_assignments' => 'required|array|min:1',
            'role_assignments.*.booking_type_id' => 'required|integer|exists:booking_types,id',
            // Required only for a booking type that has the role (see withValidator): an infosec/ICT type has no approver, and
            // the barber type no vice approver, so a flag that is never offered on the screen is not demanded here either.
            'role_assignments.*.is_approver' => 'sometimes|boolean',
            'role_assignments.*.is_vice_approver' => 'sometimes|boolean',
        ];

        foreach (BookingTypeHasRole::approverManagementRoleEnums() as $roleEnum) {
            $flagRuleKey = 'role_assignments.*.is_' . $roleEnum->toSpatieRoleName();

            $rules[$flagRuleKey] ??= 'sometimes|boolean';
        }

        return $rules;
    }

    /** A role the booking type has must be answered (true / false); a role it does not have is not offered, and the service ignores it. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $answeredRoleIds = array_map(fn (RoleEnum $roleEnum): int => $roleEnum->value, self::ANSWERED_ROLES);

            $roleIdsByBookingType = BookingTypeHasRole::query()
                ->whereIn('role_id', $answeredRoleIds)
                ->get(['booking_type_id', 'role_id'])
                ->groupBy(fn (BookingTypeHasRole $link): int => (int) $link->booking_type_id)
                ->map(fn ($links) => $links->pluck('role_id')->map(fn ($id): int => (int) $id)->all());

            foreach ((array) $this->input('role_assignments', []) as $index => $assignment) {
                if (!is_array($assignment)) {
                    continue;
                }

                $bookingTypeRoleIds = $roleIdsByBookingType->get((int) ($assignment['booking_type_id'] ?? 0), []);

                foreach (self::ANSWERED_ROLES as $roleEnum) {
                    $flagKey = 'is_' . $roleEnum->toSpatieRoleName();

                    if (in_array($roleEnum->value, $bookingTypeRoleIds, true) && !array_key_exists($flagKey, $assignment)) {
                        $validator->errors()->add("role_assignments.{$index}.{$flagKey}", 'אחד או יותר מהנתונים שנשלחו אינו תקין.');
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        $messages = [
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
        ];

        // The same generic message for every per-role flag, the domain roles included.
        foreach (BookingTypeHasRole::approverManagementRoleEnums() as $roleEnum) {
            $flagRuleKey = 'role_assignments.*.is_' . $roleEnum->toSpatieRoleName();

            $messages[$flagRuleKey . '.boolean'] ??= 'אחד או יותר מהנתונים שנשלחו אינו תקין.';
        }

        return $messages;
    }

}

<?php

namespace App\Enums;

enum RoleEnum: int
{
    case SUPER_ADMIN = 1;
    case ADMIN = 2;
    case APPROVER = 3;
    case VICE_APPROVER = 4;
    case USER = 5;

    // Control officers (רמ"ד תו"ב), each bound to one booking type; ids shared with the mobile app.
    // 2026-11-25 (the mobile's flow switch): role 7 moved from in-unit (11) to OUT-unit food (10), renamed in place - same id 7.
    case TRANSPORTATION_CONTROL_OFFICER = 6;
    case OUT_UNIT_FOOD_CONTROL_OFFICER = 7;

    // Web-only roles, banded per system (SystemDomainEnum) so a future mobile role cannot collide and each web
    // system grows in its own block: ביטחון מידע = 101-149, תקשוב = 151-199.

    // ביטחון מידע: the infosec officer (במ) owns its types; on the clearance grant route (type 14) the departmental
    // security officer (קב"ט) submits and the interview coordinator (במ אט"ל / שלוחת התחקיר) schedules the interview.
    case INFOSEC_OFFICER = 101;
    case DEPARTMENT_SECURITY_OFFICER = 103;
    case INTERVIEW_COORDINATOR = 104;

    // תקשוב: the legacy single handler (ict_handler, kept for ict:migrate-handlers) and the service desk commander
    // (מפקד תקשוב, admin_ict) who owns the ICT route (types 20-25) - dashboard, users of the desk, settings.
    case ICT_HANDLER = 151;
    case ADMIN_ICT = 152;

    /** Hebrew display name of the role. */
    public function getPermissionDisplayName(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'מנהל מערכת',
            self::ADMIN => 'מפקד מחנה',
            self::APPROVER => 'מפקד תחום',
            // Renamed from 'גורם מטפל' on the mobile side (2026-11-25); the ICT types override this to 'נציג מוקד' in the seeder.
            self::VICE_APPROVER => 'מש"ק תחום',
            self::USER => 'משתמש ',
            self::TRANSPORTATION_CONTROL_OFFICER => 'רמ"ד תו"ב',
            self::OUT_UNIT_FOOD_CONTROL_OFFICER => 'רמ"ד תו"ב',
            self::INFOSEC_OFFICER => 'קצין ביטחון מידע',
            self::ICT_HANDLER => 'מנהל תקשוב',
            self::DEPARTMENT_SECURITY_OFFICER => 'קב"ט מחלקתי',
            self::INTERVIEW_COORDINATOR => 'מתאם תחקיר',
            self::ADMIN_ICT => 'מפקד תקשוב',
        };
    }

    /** Maps a Spatie role name ("admin") to the enum. */
    public static function fromSpatieRole(string $roleName): ?self
    {
        return match ($roleName) {
            'super_admin' => self::SUPER_ADMIN,
            'admin' => self::ADMIN,
            'approver' => self::APPROVER,
            'vice_approver' => self::VICE_APPROVER,
            'user' => self::USER,
            'transportation_control_officer' => self::TRANSPORTATION_CONTROL_OFFICER,
            'out_unit_food_control_officer' => self::OUT_UNIT_FOOD_CONTROL_OFFICER,
            'infosec_officer' => self::INFOSEC_OFFICER,
            'ict_handler' => self::ICT_HANDLER,
            'department_security_officer' => self::DEPARTMENT_SECURITY_OFFICER,
            'interview_coordinator' => self::INTERVIEW_COORDINATOR,
            'admin_ict' => self::ADMIN_ICT,
            default => null,
        };
    }

    /** The matching PermissionEnum prefix, e.g. ADMIN -> "admin_". */
    public function toPermissionEnum(): PermissionEnum
    {
        return match ($this) {
            self::SUPER_ADMIN => PermissionEnum::SUPER_ADMIN,
            self::ADMIN => PermissionEnum::ADMIN,
            self::APPROVER => PermissionEnum::APPROVER,
            self::VICE_APPROVER => PermissionEnum::VICE_APPROVER,
            self::USER => PermissionEnum::USER,
            self::TRANSPORTATION_CONTROL_OFFICER => PermissionEnum::TRANSPORTATION_CONTROL_OFFICER,
            self::OUT_UNIT_FOOD_CONTROL_OFFICER => PermissionEnum::OUT_UNIT_FOOD_CONTROL_OFFICER,
            self::INFOSEC_OFFICER => PermissionEnum::INFOSEC_OFFICER,
            self::ICT_HANDLER => PermissionEnum::ICT_HANDLER,
            self::DEPARTMENT_SECURITY_OFFICER => PermissionEnum::DEPARTMENT_SECURITY_OFFICER,
            self::INTERVIEW_COORDINATOR => PermissionEnum::INTERVIEW_COORDINATOR,
            self::ADMIN_ICT => PermissionEnum::ADMIN_ICT,
        };
    }

    /** Like toPermissionEnum(), but a control officer acts as VICE_APPROVER outside their own booking type. */
    public function toPermissionEnumForBookingType(int $bookingTypeId): PermissionEnum
    {
        $controlOfficerBookingType = $this->controlOfficerBookingType();

        if ($controlOfficerBookingType !== null) {
            return $bookingTypeId === $controlOfficerBookingType->getBookingTypeId()
                ? $this->toPermissionEnum()
                : PermissionEnum::VICE_APPROVER;
        }

        return $this->toPermissionEnum();
    }

    /** Every control-officer (רמ"ד תו"ב) role, each bound to one booking type. @return array<int, self> */
    public static function controlOfficerRoles(): array
    {
        return [
            self::TRANSPORTATION_CONTROL_OFFICER,
            self::OUT_UNIT_FOOD_CONTROL_OFFICER,
        ];
    }

    /** The booking type this control-officer role belongs to, or null for a regular role. */
    public function controlOfficerBookingType(): ?BookingTypeEnum
    {
        return match ($this) {
            self::TRANSPORTATION_CONTROL_OFFICER => BookingTypeEnum::TRANSPORTATION_BOOKING,
            self::OUT_UNIT_FOOD_CONTROL_OFFICER => BookingTypeEnum::OUT_UNIT_FOOD_BOOKING,
            default => null,
        };
    }

    /** The control-officer role of a booking type, or null when its route has no רמ"ד תו"ב step. */
    public static function controlOfficerRoleForBookingType(int $bookingTypeId): ?self
    {
        foreach (self::controlOfficerRoles() as $controlOfficerRole) {
            if ($controlOfficerRole->controlOfficerBookingType()?->getBookingTypeId() === $bookingTypeId) {
                return $controlOfficerRole;
            }
        }

        return null;
    }

    /** The Spatie role name, e.g. ADMIN -> "admin". */
    public function toSpatieRoleName(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'super_admin',
            self::ADMIN => 'admin',
            self::APPROVER => 'approver',
            self::VICE_APPROVER => 'vice_approver',
            self::USER => 'user',
            self::TRANSPORTATION_CONTROL_OFFICER => 'transportation_control_officer',
            self::OUT_UNIT_FOOD_CONTROL_OFFICER => 'out_unit_food_control_officer',
            self::INFOSEC_OFFICER => 'infosec_officer',
            self::ICT_HANDLER => 'ict_handler',
            self::DEPARTMENT_SECURITY_OFFICER => 'department_security_officer',
            self::INTERVIEW_COORDINATOR => 'interview_coordinator',
            self::ADMIN_ICT => 'admin_ict',
        };
    }

    /** Whether this role is a domain-specific handler (acts on the bookings of its own types instead of the approver chain). */
    public function isDomainHandler(): bool
    {
        return in_array($this, [self::INFOSEC_OFFICER, self::ICT_HANDLER, self::INTERVIEW_COORDINATOR], true);
    }

    /** A role that SUBMITS requests (the requester side of a booking type): the plain user, or the type's own requester role. */
    public function isRequesterRole(): bool
    {
        return in_array($this, [self::USER, self::DEPARTMENT_SECURITY_OFFICER], true);
    }

    /** A role the mobile app does not know: it must never travel in the users file. */
    public function isWebOnly(): bool
    {
        return $this->value >= self::INFOSEC_OFFICER->value;
    }
}

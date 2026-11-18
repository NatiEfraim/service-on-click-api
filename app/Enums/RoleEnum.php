<?php

namespace App\Enums;

enum RoleEnum: int
{
    case SUPER_ADMIN = 1;
    case ADMIN = 2;
    case APPROVER = 3;
    case VICE_APPROVER = 4;
    case USER = 5;

    case TRANSPORTATION_CONTROL_OFFICER = 6;

    // Domain-specific handlers for infosec and ICT booking types.
    case INFOSEC_OFFICER = 7;
    case ICT_HANDLER = 8;

    // The clearance grant route (type 14): the departmental security officer (קב"ט) submits, the interview coordinator
    // (במ אט"ל / שלוחת התחקיר) schedules the interview; the infosec officer (במ) owns the route.
    case DEPARTMENT_SECURITY_OFFICER = 9;
    case INTERVIEW_COORDINATOR = 10;

    /** Hebrew display name of the role. */
    public function getPermissionDisplayName(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'מנהל מערכת',
            self::ADMIN => 'מפקד מחנה',
            self::APPROVER => 'מפקד תחום',
            self::VICE_APPROVER => 'גורם מטפל',
            self::USER => 'משתמש ',
            self::TRANSPORTATION_CONTROL_OFFICER => 'רמ"ד תו"ב',
            self::INFOSEC_OFFICER => 'קצין ביטחון מידע',
            self::ICT_HANDLER => 'מנהל תקשוב',
            self::DEPARTMENT_SECURITY_OFFICER => 'קב"ט מחלקתי',
            self::INTERVIEW_COORDINATOR => 'מתאם תחקיר',
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
            'infosec_officer' => self::INFOSEC_OFFICER,
            'ict_handler' => self::ICT_HANDLER,
            'department_security_officer' => self::DEPARTMENT_SECURITY_OFFICER,
            'interview_coordinator' => self::INTERVIEW_COORDINATOR,
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
            self::INFOSEC_OFFICER => PermissionEnum::INFOSEC_OFFICER,
            self::ICT_HANDLER => PermissionEnum::ICT_HANDLER,
            self::DEPARTMENT_SECURITY_OFFICER => PermissionEnum::DEPARTMENT_SECURITY_OFFICER,
            self::INTERVIEW_COORDINATOR => PermissionEnum::INTERVIEW_COORDINATOR,
        };
    }

    /** Like toPermissionEnum(), but a control officer acts as VICE_APPROVER outside TRANSPORTATION_BOOKING. */
    public function toPermissionEnumForBookingType(int $bookingTypeId): PermissionEnum
    {
        if ($this === self::TRANSPORTATION_CONTROL_OFFICER) {
            $isTransportationBooking = $bookingTypeId === BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId();

            return $isTransportationBooking
                ? PermissionEnum::TRANSPORTATION_CONTROL_OFFICER
                : PermissionEnum::VICE_APPROVER;
        }

        return $this->toPermissionEnum();
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
            self::INFOSEC_OFFICER => 'infosec_officer',
            self::ICT_HANDLER => 'ict_handler',
            self::DEPARTMENT_SECURITY_OFFICER => 'department_security_officer',
            self::INTERVIEW_COORDINATOR => 'interview_coordinator',
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

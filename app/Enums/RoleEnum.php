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
        };
    }

    /** Whether this role is a domain-specific handler (acts like an approver for its own types). */
    public function isDomainHandler(): bool
    {
        return in_array($this, [self::INFOSEC_OFFICER, self::ICT_HANDLER], true);
    }
}

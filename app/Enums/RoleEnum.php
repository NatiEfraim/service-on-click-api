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

    /** Get the display name for permissions based on the role and booking type. */
    public function getPermissionDisplayName(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'מנהל מערכת',
            self::ADMIN => 'מפקד מחנה',
            self::APPROVER => 'מפקד תחום',
            self::VICE_APPROVER => 'גורם מטפל',
            self::USER => 'משתמש ',
            self::TRANSPORTATION_CONTROL_OFFICER => 'רמ"ד תו"ב',
        };
    }


    /** Convert a Spatie role string ("admin") into the matching RoleEnum value. */
    public static function fromSpatieRole(string $roleName): ?self
    {
        return match ($roleName) {
            'super_admin' => self::SUPER_ADMIN,
            'admin' => self::ADMIN,
            'approver' => self::APPROVER,
            'vice_approver' => self::VICE_APPROVER,
            'user' => self::USER,
            'transportation_control_officer' => self::TRANSPORTATION_CONTROL_OFFICER,
            default => null,
        };
    }

    /** Map a RoleEnum value to its matching PermissionEnum prefix, e.g. ADMIN → "admin_". */
    public function toPermissionEnum(): PermissionEnum
    {
        return match ($this) {
            self::SUPER_ADMIN => PermissionEnum::SUPER_ADMIN,
            self::ADMIN => PermissionEnum::ADMIN,
            self::APPROVER => PermissionEnum::APPROVER,
            self::VICE_APPROVER => PermissionEnum::VICE_APPROVER,
            self::USER => PermissionEnum::USER,
            self::TRANSPORTATION_CONTROL_OFFICER => PermissionEnum::TRANSPORTATION_CONTROL_OFFICER,
        };
    }

    /** Map to permission; control officer -> VICE_APPROVER unless TRANSPORTATION_BOOKING. @param int $bookingTypeId @return PermissionEnum */
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

    /** Convert RoleEnum into the string used by Spatie roles table, e.g. ADMIN → "admin". */
    public function toSpatieRoleName(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'super_admin',
            self::ADMIN => 'admin',
            self::APPROVER => 'approver',
            self::VICE_APPROVER => 'vice_approver',
            self::USER => 'user',
            self::TRANSPORTATION_CONTROL_OFFICER => 'transportation_control_officer',
        };
    }
}

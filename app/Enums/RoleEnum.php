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
        };
    }
}

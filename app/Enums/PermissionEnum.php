<?php

namespace App\Enums;

enum PermissionEnum: string
{
    case SUPER_ADMIN = 'super_admin_';
    case ADMIN = 'admin_';
    case APPROVER = 'approver_';
    case VICE_APPROVER = 'vice_approver_';
    case USER = 'user_';

    /** "רמ"ד תו"ב" - only seeded for TRANSPORTATION_BOOKING (see RoleEnum::TRANSPORTATION_CONTROL_OFFICER). */
    case TRANSPORTATION_CONTROL_OFFICER = 'transportation_control_officer_';

    public function trimSuffix(): string
    {
        return rtrim(string: $this->value, characters: '_');
    }

    /** Build "admin_17", "approver_3", etc. from a booking-type ID */
    public function keyForId(int $bookingTypeId): ?string
    {
        return $this->value . $bookingTypeId;
    }

    /** Build key from a BookingTypeEnum, or null if that booking type has no DB id */
    public function keyForBooking(BookingTypeEnum $bookingType): ?string
    {
        $id = $bookingType->getBookingTypeId();
        return $id ? $this->keyForId($id) : null;
    }

    /** @return array<int, self> Every prefix, longest first - so "vice_approver_" is tested before "approver_". */
    public static function casesByPrefixLength(): array
    {
        $cases = self::cases();

        usort(
            $cases,
            fn(self $first, self $second): int => strlen($second->value) <=> strlen($first->value)
        );

        return $cases;
    }

    /** @return array{permission: self, booking_type_id: int}|null Split "admin_1" into [ADMIN, 1]. */
    public static function parsePermissionName(string $permissionName): ?array
    {
        $permissionName = trim($permissionName);

        foreach (self::casesByPrefixLength() as $permission) {
            if (!str_starts_with($permissionName, $permission->value)) {
                continue;
            }

            $bookingTypeId = substr($permissionName, strlen($permission->value));

            if ($bookingTypeId === '' || !ctype_digit($bookingTypeId)) {
                continue;
            }

            return [
                'permission' => $permission,
                'booking_type_id' => (int) $bookingTypeId,
            ];
        }

        return null;
    }

    /** The matching RoleEnum for this permission prefix. */
    public function toRoleEnum(): RoleEnum
    {
        return match ($this) {
            self::SUPER_ADMIN => RoleEnum::SUPER_ADMIN,
            self::ADMIN => RoleEnum::ADMIN,
            self::APPROVER => RoleEnum::APPROVER,
            self::VICE_APPROVER => RoleEnum::VICE_APPROVER,
            self::USER => RoleEnum::USER,
            self::TRANSPORTATION_CONTROL_OFFICER => RoleEnum::TRANSPORTATION_CONTROL_OFFICER,
        };
    }

    /** Hebrew display name of a permission name - the seeded permissions.display_name is authoritative. */
    public static function displayNameFromPermissionName(string $permissionName): ?string
    {
        $parsed = self::parsePermissionName($permissionName);

        if (!$parsed) {
            return null;
        }

        $displayName = \Spatie\Permission\Models\Permission::query()
            ->where('name', $permissionName)
            ->value('display_name');

        if (is_string($displayName) && trim($displayName) !== '') {
            return trim($displayName);
        }

        return self::buildDisplayName(
            permission: $parsed['permission'],
            bookingTypeId: $parsed['booking_type_id'],
        );
    }

    /** Rebuild "<role display name> <booking type display name>", the same shape the seeder writes. */
    public static function buildDisplayName(self $permission, int $bookingTypeId): ?string
    {
        $bookingTypeDisplayName = \App\Models\BookingType::query()
            ->where('id', $bookingTypeId)
            ->value('display_name');

        if (!$bookingTypeDisplayName) {
            return null;
        }

        return trim(
            $permission->toRoleEnum()->getPermissionDisplayName()
            . ' '
            . $bookingTypeDisplayName
        );
    }
}

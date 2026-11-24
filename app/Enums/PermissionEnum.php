<?php

namespace App\Enums;

enum PermissionEnum: string
{
    case SUPER_ADMIN = 'super_admin_';
    case ADMIN = 'admin_';
    case APPROVER = 'approver_';
    case VICE_APPROVER = 'vice_approver_';
    case USER = 'user_';

    // Control officers (רמ"ד תו"ב), each seeded only for their own booking type.
    case TRANSPORTATION_CONTROL_OFFICER = 'transportation_control_officer_';
    case IN_UNIT_FOOD_CONTROL_OFFICER = 'in_unit_food_control_officer_';

    // Domain-specific handlers: seeded only for their own booking types.
    case INFOSEC_OFFICER = 'infosec_officer_';
    case ICT_HANDLER = 'ict_handler_';

    // The clearance grant route (type 14): its requester role and its interview coordinator.
    case DEPARTMENT_SECURITY_OFFICER = 'department_security_officer_';
    case INTERVIEW_COORDINATOR = 'interview_coordinator_';

    public function trimSuffix(): string
    {
        return rtrim(string: $this->value, characters: '_');
    }

    /** Builds "admin_1", "approver_3", etc. */
    public function keyForId(int $bookingTypeId): ?string
    {
        return $this->value . $bookingTypeId;
    }

    /** Same as keyForId(), or null when the booking type has no DB id. */
    public function keyForBooking(BookingTypeEnum $bookingType): ?string
    {
        $id = $bookingType->getBookingTypeId();
        return $id ? $this->keyForId($id) : null;
    }

    /** @return array<int, self> Longest prefix first, so "vice_approver_" is tried before "approver_". */
    public static function casesByPrefixLength(): array
    {
        $cases = self::cases();

        usort(
            $cases,
            fn(self $first, self $second): int => strlen($second->value) <=> strlen($first->value)
        );

        return $cases;
    }

    /** @return array{permission: self, booking_type_id: int}|null Splits "admin_1" into [ADMIN, 1]. */
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

    public function toRoleEnum(): RoleEnum
    {
        return match ($this) {
            self::SUPER_ADMIN => RoleEnum::SUPER_ADMIN,
            self::ADMIN => RoleEnum::ADMIN,
            self::APPROVER => RoleEnum::APPROVER,
            self::VICE_APPROVER => RoleEnum::VICE_APPROVER,
            self::USER => RoleEnum::USER,
            self::TRANSPORTATION_CONTROL_OFFICER => RoleEnum::TRANSPORTATION_CONTROL_OFFICER,
            self::IN_UNIT_FOOD_CONTROL_OFFICER => RoleEnum::IN_UNIT_FOOD_CONTROL_OFFICER,
            self::INFOSEC_OFFICER => RoleEnum::INFOSEC_OFFICER,
            self::ICT_HANDLER => RoleEnum::ICT_HANDLER,
            self::DEPARTMENT_SECURITY_OFFICER => RoleEnum::DEPARTMENT_SECURITY_OFFICER,
            self::INTERVIEW_COORDINATOR => RoleEnum::INTERVIEW_COORDINATOR,
        };
    }

    /** Hebrew display name; the seeded permissions.display_name wins. */
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

    /** Builds "<role name> <booking type name>", as the seeder does. */
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

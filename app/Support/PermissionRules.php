<?php

namespace App\Support;

use App\Enums\PermissionEnum;
use App\Models\BookingTypeHasRole;

/**
 * Which "{role}_{booking type id}" permissions are real grants: the booking type HAS the role (booking_type_has_roles). A permission of a
 * pair the type does not have (infosec_officer_1: the shared cars have no infosec officer) can come from nowhere the screens know - an old
 * seeder run, a manual grant - and must never open or manage anything: every place that reads a person's permissions to decide what they may
 * do goes through onlyValid(), and every place that writes one refuses a pair that is not valid (hasLink()).
 * A name that is not of the "{role}_{type}" scheme is not judged. One instance per application (a request, a test), so the links are read once.
 */
final class PermissionRules
{
    /** @var ?array<string, true> "role id:booking type id" => true */
    private ?array $links = null;

    /** Whether the booking type has the role (is the role offered on it). */
    public function hasLink(int $roleId, int $bookingTypeId): bool
    {
        $this->links ??= BookingTypeHasRole::query()
            ->get(['booking_type_id', 'role_id'])
            ->mapWithKeys(fn (BookingTypeHasRole $link): array => [((int) $link->role_id) . ':' . ((int) $link->booking_type_id) => true])
            ->all();

        return isset($this->links[$roleId . ':' . $bookingTypeId]);
    }

    /** Whether a permission name is a real grant: not of the "{role}_{type}" scheme (left alone), or on a type that has the role. */
    public function isValid(string $permissionName): bool
    {
        $parsed = PermissionEnum::parsePermissionName($permissionName);

        return $parsed === null || $this->hasLink($parsed['permission']->toRoleEnum()->value, $parsed['booking_type_id']);
    }

    /**
     * The names that are real grants, in the order given.
     * @param iterable<string> $permissionNames
     * @return array<int, string>
     */
    public function onlyValid(iterable $permissionNames): array
    {
        $valid = [];

        foreach ($permissionNames as $name) {
            if ($this->isValid($name)) {
                $valid[] = $name;
            }
        }

        return $valid;
    }
}

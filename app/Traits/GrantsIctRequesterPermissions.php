<?php

namespace App\Traits;

use App\Enums\BookingTypeEnum;
use App\Enums\PermissionEnum;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Grants the minimum user_{typeId} permission for every ICT (תקשוב) booking type.
 * ICT types are web-only and unit-independent: any person who can log in via ADFS may open them,
 * regardless of which unit they belong to.
 * Used by UserService::findForAdfsLogin so the grant runs once, transparently, on first login.
 */
trait GrantsIctRequesterPermissions
{
    /**
     * Ensures the user holds user_{typeId} for all 9 ICT booking types.
     * Permissions that already exist on the user are left alone; nothing is removed.
     * No markRolesChanged() call is needed: ICT types are web-only and never reach the mobile app.
     */
    protected function ensureIctRequesterPermissions(User $user): void
    {
        $typeIds = array_values(array_filter(array_map(
            fn (BookingTypeEnum $type): ?int => $type->getBookingTypeId(),
            BookingTypeEnum::ictCases(),
        )));

        if ($typeIds === []) {
            return;
        }

        $needed  = array_map(fn (int $id): string => PermissionEnum::USER->keyForId($id), $typeIds);
        $owned   = $user->getAllPermissions()->pluck('name')->all();
        $missing = array_diff($needed, $owned);

        if ($missing === []) {
            return;
        }

        $permissions = Permission::query()->whereIn('name', array_values($missing))->get();

        if ($permissions->isEmpty()) {
            return;
        }

        $user->givePermissionTo($permissions);
    }
}

<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\RoleEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    private const GUARD_NAME = 'passport';

    /** The roles of the web system - role ids are the RoleEnum values, the same ids the mobile app uses. @var array<int, RoleEnum> */
    private const ROLES = [
        RoleEnum::SUPER_ADMIN,
        RoleEnum::ADMIN,
        RoleEnum::APPROVER,
        RoleEnum::VICE_APPROVER,
        // Everyone else who logs in: sees only their own bookings (GET /bookings/my-bookings and GET /bookings/{uuid}).
        RoleEnum::USER,
    ];

    public function run(): void
    {
        try {
            DB::beginTransaction();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $this->createRoles();
            (new BookingTypeHasRoleSeeder())->run();
            $this->createPermissions();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('RoleAndPermissionSeeder failed message:' . $e->getMessage());
            throw $e;
        }
    }

    private function createRoles(): void
    {
        foreach (self::ROLES as $roleEnum) {
            // The id is pinned to the enum value: booking_type_has_roles and the permission checks read roles by it.
            Role::unguarded(fn() => Role::updateOrCreate(
                [
                    'id' => $roleEnum->value,
                ],
                [
                    'name' => $roleEnum->toSpatieRoleName(),
                    'display_name' => $roleEnum->getPermissionDisplayName(),
                    'guard_name' => self::GUARD_NAME,
                ]
            ));
        }
    }

    /** Create a "{role_prefix}_{booking_type_id}" permission for every (booking type, role) pair. */
    private function createPermissions(): void
    {
        $bookingTypes = BookingType::query()->get(['id', 'display_name']);

        $roleIdsByBookingTypeId = BookingTypeHasRole::query()
            ->get(['booking_type_id', 'role_id'])
            ->groupBy('booking_type_id')
            ->map(fn($rows) => $rows->pluck('role_id')->map(fn($id) => (int) $id)->unique());

        foreach ($bookingTypes as $bookingType) {
            $roleIds = $roleIdsByBookingTypeId->get($bookingType->id) ?? collect();

            foreach ($roleIds as $roleId) {
                $roleEnum = RoleEnum::tryFrom($roleId);

                if (!$roleEnum) {
                    continue;
                }

                $permissionName = $roleEnum->toPermissionEnum()->keyForId($bookingType->id);

                if (!$permissionName) {
                    continue;
                }

                Permission::updateOrCreate(
                    [
                        'name' => $permissionName,
                        'guard_name' => self::GUARD_NAME,
                    ],
                    [
                        'display_name' => $roleEnum->getPermissionDisplayName() . ' ' . $bookingType->display_name,
                    ]
                );
            }
        }
    }
}

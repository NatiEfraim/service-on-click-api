<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\PermissionEnum;
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

    /** Role ids are the RoleEnum values, shared with the mobile app. @var array<int, RoleEnum> */
    private const ROLES = [
        RoleEnum::SUPER_ADMIN,
        RoleEnum::ADMIN,
        RoleEnum::APPROVER,
        RoleEnum::VICE_APPROVER,
        // Everyone else: sees only their own bookings.
        RoleEnum::USER,
        // Control officers (רמ"ד תו"ב), each linked to one booking type (BookingTypeHasRoleSeeder): the first stage of its route.
        RoleEnum::TRANSPORTATION_CONTROL_OFFICER,
        RoleEnum::IN_UNIT_FOOD_CONTROL_OFFICER,
        // Domain-specific handlers for infosec and ICT types (web-only ids, 101+).
        RoleEnum::INFOSEC_OFFICER,
        RoleEnum::ICT_HANDLER,
        // The clearance grant route (type 14): its requester and its interview coordinator.
        RoleEnum::DEPARTMENT_SECURITY_OFFICER,
        RoleEnum::INTERVIEW_COORDINATOR,
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
            // Id pinned to the enum value; booking_type_has_roles and permission checks read roles by it.
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

    /**
     * Create a "{role_prefix}_{booking_type_id}" permission for every (booking type, role) pair, and drop the permissions of pairs a type no
     * longer has (e.g. user_14 once the clearance grant got its own requester role) so nobody keeps a grant the type does not know.
     */
    private function createPermissions(): void
    {
        $bookingTypes = BookingType::query()->get(['id', 'display_name']);

        $roleIdsByBookingTypeId = BookingTypeHasRole::query()
            ->get(['booking_type_id', 'role_id'])
            ->groupBy('booking_type_id')
            ->map(fn($rows) => $rows->pluck('role_id')->map(fn($id) => (int) $id)->unique());

        $expected = [];

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

                $expected[$permissionName] = true;

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

        // Only names of the "{role}_{type}" scheme are judged; anything else is left alone.
        foreach (Permission::query()->where('guard_name', self::GUARD_NAME)->get() as $permission) {
            if (PermissionEnum::parsePermissionName($permission->name) !== null && !isset($expected[$permission->name])) {
                $permission->delete();
            }
        }
    }
}

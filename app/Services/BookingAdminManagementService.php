<?php

namespace App\Services;

use App\Enums\BookingTypeEnum;
use App\Enums\HttpStatusCodeEnum;
use App\Enums\PaginationEnum;
use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Http\Resources\UserWithPermissionIconResource;
use App\Models\User;



class BookingAdminManagementService
{
    const MIN_LEN = 1;
    const MAX_LEN = 9;

    protected $authUser;
    public function __construct(private readonly StorageService $storageService)
    {
        $this->authUser = Auth::user();
    }


    public function getApprovers(?string $identifier = null): HttpStatusCodeEnum|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|array
    {
        try {
            $roles = $this->getApproverManagementRoles();
            $roleIds = $roles->map(fn(RoleEnum $roleEnum) => $roleEnum->value)->all();
            $permissionPrefixes = $roles->map(fn(RoleEnum $roleEnum) => $roleEnum->toPermissionEnum()->value)->all();

            $users = User::whereHas('roles', function ($query) use ($roleIds) {
                $query->whereIn('id', $roleIds);
            })
                ->when($identifier, function ($query, $identifier) {
                    $query->where(function ($q) use ($identifier) {
                        $q->where('personal_number', 'like', $identifier . '%')
                            ->orWhere('full_name', 'like', $identifier . '%');
                    });
                })
                ->with([
                    'roles',
                    'permissions' => function ($query) use ($permissionPrefixes) {
                        $query->where(function ($q) use ($permissionPrefixes) {
                            foreach ($permissionPrefixes as $permissionPrefix) {
                                $q->orWhere('name', 'like', $permissionPrefix . '%');
                            }
                        });
                    }
                ])->paginate(PaginationEnum::PER_PAGE->value);
            return UserWithPermissionIconResource::collection($users);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Get booking types the auth user manages, with the user's approver role flags. @param string $userUuid @return array|HttpStatusCodeEnum */
    public function getApproverPermissions(string $userUuid): array|HttpStatusCodeEnum
    {
        try {

            $bookingTypeIds = $this->authUser->getAllPermissions()->filter(function ($permission) {
                return str_starts_with($permission->name, 'admin_');
            })->map(function ($permission) {
                $parts = explode('_', $permission->name);
                return end($parts);
            })->unique()->values();


            $user = User::where('uuid', $userUuid)->with(['roles', 'permissions'])
                ->where('is_deleted', false)->first();

            if (!$user) {
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            $approverManagementRoles = $this->getApproverManagementRoles();

            $bookingTypes = BookingType::whereIn('id', $bookingTypeIds)
                ->where('id', '!=', BookingTypeEnum::EQUIPMENT_BOOKING->getBookingTypeId())
                ->where('is_outsource', false)
                ->with('roles')
                ->get();

            $result = $bookingTypes->map(function ($bookingType) use ($user, $approverManagementRoles): array {

                $bookingTypeId = $bookingType['id'];

                // Roles linked to this booking type via booking_type_has_roles.
                $applicableRoleNames = $bookingType->roles->pluck('name');

                $flags = $approverManagementRoles
                    ->filter(fn(RoleEnum $roleEnum) => $applicableRoleNames->contains($roleEnum->toSpatieRoleName()))
                    ->mapWithKeys(function (RoleEnum $roleEnum) use ($user, $bookingTypeId) {
                        $permissionName = $roleEnum->toPermissionEnum()->keyForId($bookingTypeId);

                        return [
                            'is_' . $roleEnum->toSpatieRoleName() => $permissionName
                                ? (optional($user)->hasPermissionTo($permissionName) ?? false)
                                : false,
                        ];
                    })
                    ->all();

                return array_merge(
                    [
                        'booking_type_id' => $bookingTypeId,
                        'name' => $bookingType['name'],
                        'display_name' => 'ניהול ' . $bookingType['display_name'],
                        'icon' => $bookingType['icon'] ?? null,
                    ],
                    $flags,
                );
            });

            return [
                'role_options' => $approverManagementRoles
                    ->mapWithKeys(fn(RoleEnum $roleEnum) => [$roleEnum->toSpatieRoleName() => $roleEnum->value])
                    ->all(),
                'user' => [
                    'full_name' => $user->full_name,
                    'personal_number' => $user->personal_number,
                ],
                'permissions' => $result->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Assign or revoke approver roles/permissions per booking type for a user. @param array $validated @return mixed */
    public function assignApproverRole(array $validated = []): mixed
    {

        try {

            $approverManagementRoles = $this->getApproverManagementRoles();

            DB::beginTransaction();

            $user = $this->resolveApproverUser(validated: $validated);

            if (is_null($user)) {
                DB::rollBack();
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            foreach ($validated['role_assignments'] as $assignment) {
                $bookingTypeId = $assignment['booking_type_id'];

                $applicableRoleIds = BookingTypeHasRole::query()
                    ->where('booking_type_id', $bookingTypeId)
                    ->pluck('role_id')
                    ->map(fn($id) => (int) $id);

                foreach ($approverManagementRoles as $roleEnum) {
                    if (!$applicableRoleIds->contains($roleEnum->value)) {
                        continue;
                    }

                    $flagKey = 'is_' . $roleEnum->toSpatieRoleName();

                    if (!array_key_exists($flagKey, $assignment)) {
                        continue;
                    }

                    $isGranted = (bool) $assignment[$flagKey];

                    $role = Role::where('id', $roleEnum->value)->first();
                    $permissionName = $roleEnum->toPermissionEnum()->keyForId($bookingTypeId);
                    $permission = $permissionName
                        ? Permission::where('name', $permissionName)->first()
                        : null;

                    if (!$role || !$permission) {
                        DB::rollBack();
                        return HttpStatusCodeEnum::UNPROCESSABLE_ENTITY;
                    }

                    if ($isGranted) {
                        $user->assignRole($role);
                        $user->givePermissionTo($permission);
                    } else {
                        $user->revokePermissionTo($permission);
                    }
                }
            }

            foreach ($approverManagementRoles as $roleEnum) {
                $this->cleanupRolePermissions(user: $user, rolePrefix: $roleEnum->toPermissionEnum()->trimSuffix());
            }

            DB::commit();
            return $this->getApprovers(identifier: $user->personal_number);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Fetch the user by uuid or personal number, creating one when no uuid is sent and none exists. @param array $validated @return ?User */
    private function resolveApproverUser(array $validated): ?User
    {
        if (!empty($validated['uuid'])) {
            return User::with(['roles', 'permissions'])
                ->where('uuid', $validated['uuid'])
                ->first();
        }

        $personalNumber = $validated['personal_number'] ?? null;

        if (is_null($personalNumber)) {
            return null;
        }

        $user = User::with(['roles', 'permissions'])
            ->where('personal_number', $personalNumber)
            ->first();

        if (!is_null($user)) {
            return $user;
        }

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => $validated['full_name'] ?? null,
            'personal_number' => $personalNumber,
            'phone' => $validated['phone'] ?? null,
            'unit_id' => $this->authUser->unit_id ?? null,
            'is_deleted' => false,
        ]);

        return $user->load(['roles', 'permissions']);
    }

    /** Roles used across getApprovers()/getApproverPermissions()/assignApproverRole(). @return Collection */
    private function getApproverManagementRoles(): Collection
    {
        return BookingTypeHasRole::approverManagementRoleEnums();
    }

    /** Remove a role from the user if they hold no permissions for it. @param User $user, string $rolePrefix */
    private function cleanupRolePermissions(User $user, string $rolePrefix): void
    {

        $role = Role::where('name', $rolePrefix)->first();

        if ($role) {

            $hasPermissions = $user->permissions->contains(function ($permission) use ($rolePrefix) {

                return str_starts_with($permission->name, $rolePrefix);
            });

            if (!$hasPermissions) {
                $user->removeRole($role);
            }
        }
    }
}

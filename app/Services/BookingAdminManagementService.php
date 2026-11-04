<?php

namespace App\Services;

use App\Enums\BookingTypeEnum;
use App\Enums\HttpStatusCodeEnum;
use App\Enums\PaginationEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingType;
use App\Exceptions\DomainError;
use App\Models\BookingTypeHasRole;
use App\Traits\AuditsPermissionChanges;
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
    use AuditsPermissionChanges;

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

            // An admin manages their own unit only; neither side deals with super admins, and an admin not with other admins.
            $users = User::whereHas('roles', function ($query) use ($roleIds) {
                $query->whereIn('id', $roleIds);
            })
                ->manageableBy(Auth::user())
                ->when(!$this->isSuperAdmin(), fn ($query) => $query->where('unit_id', Auth::user()->unit_id))
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

    /** Booking types the auth user manages, with the user's approver role flags. @return array|HttpStatusCodeEnum */
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

            // A person of another unit is not one an admin deals with, nor a super admin, nor (for an admin) an admin: they are not shown.
            if (!$user || !$user->isManageableBy(Auth::user()) || (!$this->isSuperAdmin() && (int) $user->unit_id !== (int) Auth::user()->unit_id)) {
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

    /** Assigns or revokes approver roles/permissions per booking type for a user. */
    public function assignApproverRole(array $validated = []): mixed
    {

        try {

            $approverManagementRoles = $this->getApproverManagementRoles();

            // Before the transaction: a refusal is audited, and that row would be rolled back inside it.
            $this->assertMayManage($validated);

            DB::beginTransaction();

            $user = $this->resolveApproverUser(validated: $validated);

            if (is_null($user)) {
                DB::rollBack();
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            $permissionsBefore = $this->permissionNamesOf($user);

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

            $this->auditPermissionChange($user, $permissionsBefore, $validated['reason'] ?? null);

            DB::commit();
            return $this->getApprovers(identifier: $user->personal_number);

        } catch (DomainError $e) {
            // A refusal, not a failure: nothing changed and the answer is the rule's own.
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /**
     * An admin grants roles only on booking types they administer and only to their own unit's people; the super admin anywhere.
     * A person who does not exist yet is created in the admin's unit, so only the booking types count.
     * @param array<string, mixed> $validated uuid or personal_number, and role_assignments
     */
    private function assertMayManage(array $validated): void
    {
        $admin = Auth::user();
        $target = $this->grantTargetOf($validated);

        if (!$this->isSuperAdmin()) {
            $administered = $admin->getAllPermissions()
                ->map(fn ($permission) => PermissionEnum::parsePermissionName($permission->name))
                ->filter(fn ($parsed) => $parsed !== null && $parsed['permission'] === PermissionEnum::ADMIN)
                ->map(fn ($parsed): int => $parsed['booking_type_id'])
                ->all();

            $outsideTypes = collect($validated['role_assignments'] ?? [])
                ->contains(fn (array $assignment): bool => !in_array((int) $assignment['booking_type_id'], $administered, true));

            if ($outsideTypes || ($target !== null && (int) $target->unit_id !== (int) $admin->unit_id)) {
                app(AuditService::class)->denied('user.permissions.change', 'user', $target?->actorId() ?? ($validated['personal_number'] ?? null), 'outside_admin_scope', $admin, ['unit_id' => $target?->unit_id]);

                throw new DomainError('ניתן להקצות הרשאות רק בתחומים שבניהולך ולמשתמשי היחידה שלך', 403);
            }

            // An admin never changes their own roles (they could widen their reach or lock themselves out).
            if ($target !== null && (int) $target->id === (int) $admin->id) {
                app(AuditService::class)->denied('user.permissions.change', 'user', $admin->actorId(), 'self_grant', $admin, ['unit_id' => $admin->unit_id]);

                throw new DomainError('לא ניתן לשנות את ההרשאות של עצמך', 403);
            }
        }

        // Nobody changes a super admin's roles or permissions, and an admin changes no admin's.
        $this->assertTargetManageable($target);
    }

    private function isSuperAdmin(): bool
    {
        return Auth::user()->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName());
    }

    /** Finds the user by uuid or personal number; creates one when no uuid is sent and none exists. */
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

    /** Roles shared by the approver management methods. */
    private function getApproverManagementRoles(): Collection
    {
        return BookingTypeHasRole::approverManagementRoleEnums();
    }

    /** Removes a role from the user if they hold no permissions for it. */
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

<?php

namespace App\Services;

use App\Exceptions\DomainError;
use App\Models\Department;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;

/** Sets department membership; an admin works on their own unit (not other admins), the super admin on any. Role grants are in the assign-*-role endpoints. */
class UserAdminService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Moves a person to a department of their unit, or out of any department with $departmentId = null.
     * @return array{uuid: string, department_id: ?int, department_name: ?string}
     */
    public function setDepartment(AccessScope $scope, string $userUuid, ?int $departmentId): array
    {
        $user = User::query()->where('uuid', $userUuid)->where('is_deleted', false)->first();

        if ($user === null) {
            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        if (!$scope->can('users.manage') || (!$scope->isSuperAdmin() && (int) $user->unit_id !== (int) $scope->user->unit_id)) {
            $this->audit->denied('user.department.change', 'user', $user->actorId(), 'no_permission', $scope->user, ['unit_id' => $user->unit_id]);

            throw new DomainError('אין לך הרשאה לשנות שיוך של משתמש זה', 403);
        }

        // A person the list does not show (a super admin; for an admin also an admin) is not one to change either.
        if (!$user->isManageableBy($scope->user)) {
            $this->audit->denied('user.department.change', 'user', $user->actorId(), 'protected_role', $scope->user, ['unit_id' => $user->unit_id]);

            throw new DomainError('אין לך הרשאה לשנות שיוך של משתמש זה', 403);
        }

        $department = null;

        if ($departmentId !== null) {
            $department = Department::query()->where('id', $departmentId)->where('unit_id', $user->unit_id)->where('is_active', true)->first()
                ?? throw new DomainError('המחלקה אינה שייכת ליחידה של המשתמש', 422);
        }

        return DB::transaction(function () use ($scope, $user, $department): array {
            $before = ['department_id' => $user->department_id];

            $user->forceFill(['department_id' => $department?->id])->save();

            $this->audit->record([
                'action' => 'user.department.change',
                'entity_type' => 'user',
                'entity_id' => $user->actorId(),
                'unit_id' => $user->unit_id,
                'before' => $before,
                'after' => ['department_id' => $department?->id],
            ], $scope->user);

            return ['uuid' => $user->uuid, 'department_id' => $department?->id, 'department_name' => $department?->name];
        });
    }
}

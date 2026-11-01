<?php

namespace App\Services;

use App\Exceptions\DomainError;
use App\Models\Department;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * What the admin (camp commander) and the super admin do with the people of a unit besides granting roles (that is the assign-*-role endpoints):
 * put a person in a department. An admin works on the people of their own unit only.
 */
class UserAdminService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Move a person to a department of their unit, or out of any department with $departmentId = null.
     * @return array{uuid: string, department_id: ?int, department_name: ?string}
     * @throws DomainError 403 not the admin's unit, 404 no such person, 422 the department is not an active one of the person's unit
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

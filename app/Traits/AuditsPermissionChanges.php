<?php

namespace App\Traits;

use App\Enums\RoleEnum;
use App\Exceptions\DomainError;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Auth;

/** Audit helpers for services that grant or revoke roles. */
trait AuditsPermissionChanges
{
    /** The person a grant is for, found the way the grant finds them: by uuid, else by personal number; null when they do not exist yet. @param array<string, mixed> $validated */
    protected function grantTargetOf(array $validated): ?User
    {
        return !empty($validated['uuid'])
            ? User::query()->where('uuid', $validated['uuid'])->first()
            : User::query()->where('personal_number', $validated['personal_number'] ?? null)->first();
    }

    /**
     * Refuses (403, audited) a grant to a person the logged-in user may not manage: a super admin is nobody's to change here, an admin is the
     * super admin's alone (see User::unmanageableRoleNames()). Call it OUTSIDE the transaction: the audit row must not be rolled back with it.
     * @throws DomainError
     */
    protected function assertTargetManageable(?User $target): void
    {
        /** @var User $actor Auth::user() is typed as the Authenticatable contract, which has no hasRole(). */
        $actor = Auth::user();

        if ($target === null || $target->isManageableBy($actor)) {
            return;
        }

        app(AuditService::class)->denied('user.permissions.change', 'user', $target->actorId(), 'protected_role', $actor, ['unit_id' => $target->unit_id]);

        throw new DomainError('לא ניתן לשנות הרשאות של מנהל מערכת' . ($actor->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName()) ? '' : ' או של מפקד מחנה'), 403);
    }

    /** @return array<int, string> The user's direct and role permission names, sorted. */
    protected function permissionNamesOf(User $user): array
    {
        return $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * Audits a permission change by the logged-in person, inside the caller's transaction. Skipped when nothing changed.
     * @param array<int, string> $before permissionNamesOf() taken before the change
     */
    protected function auditPermissionChange(User $user, array $before, ?string $reason = null): void
    {
        $after = $this->permissionNamesOf($user);

        if ($before === $after) {
            return;
        }

        // The users file for the mobile side carries updated_at; a role change is a change of the person, and the file has to carry it again
        // (is_exported_role = 0). Inside the caller's transaction, so the flag and the roles change together.
        $user->markRolesChanged();

        app(AuditService::class)->record([
            'action' => 'user.permissions.change',
            'entity_type' => 'user',
            'entity_id' => $user->actorId(),
            'unit_id' => $user->unit_id,
            'before' => ['permissions' => $before],
            'after' => ['permissions' => $after],
            'reason' => $reason,
        ], Auth::user());
    }
}

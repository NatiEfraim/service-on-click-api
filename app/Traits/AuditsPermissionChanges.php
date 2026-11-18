<?php

namespace App\Traits;

use App\Enums\BookingTypeEnum;
use App\Enums\PermissionEnum;
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

    /** Throws 403 (audited) when the target has a protected role the caller may not change. Call OUTSIDE the transaction. @throws DomainError */
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

    /** @param array<int, string> $names @return array<int, string> the names the mobile app can make sense of (no web-only role, no web-only type) */
    protected function mobileFacing(array $names): array
    {
        return array_values(array_filter($names, function (string $name): bool {
            $parsed = PermissionEnum::parsePermissionName($name);

            return $parsed === null
                || (!$parsed['permission']->toRoleEnum()->isWebOnly() && !BookingTypeEnum::isWebOnlyId($parsed['booking_type_id']));
        }));
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
        // (is_exported_role = 0). Inside the caller's transaction, so the flag and the roles change together. A change of web-only
        // permissions alone (the infosec / ICT / clearance roles and types) is nothing the mobile app needs to hear about.
        if ($this->mobileFacing($before) !== $this->mobileFacing($after)) {
            $user->markRolesChanged();
        }

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

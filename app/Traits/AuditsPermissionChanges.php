<?php

namespace App\Traits;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Auth;

/** Audit helpers for services that grant or revoke roles. */
trait AuditsPermissionChanges
{
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

        // The users file for the mobile side carries updated_at; a role change is a change of the person.
        $user->touch();

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

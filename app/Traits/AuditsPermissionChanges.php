<?php

namespace App\Traits;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Auth;

/** For the services that grant and take away roles: what a person's permissions were before, and an audit row when they are different after. */
trait AuditsPermissionChanges
{
    /** The user's permission names right now (their own and their roles'), sorted. @return array<int, string> */
    protected function permissionNamesOf(User $user): array
    {
        return $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * Record the change of the user's permissions made by the logged-in person - inside the caller's transaction, so the change and its
     * audit row stand or fall together. Nothing is written when nothing changed.
     * @param array<int, string> $before what permissionNamesOf() said before the change
     */
    protected function auditPermissionChange(User $user, array $before, ?string $reason = null): void
    {
        $after = $this->permissionNamesOf($user);

        if ($before === $after) {
            return;
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

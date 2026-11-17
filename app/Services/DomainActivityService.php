<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use App\Support\AccessScope;

/** Who looked at a booking type's dashboard and who acted on its bookings last; the approver's and vice approver's, shown to the camp commander (admin). */
class DomainActivityService
{
    private const VIEW = 'dashboard.view';

    /** What counts as an action: a status change or an edit of a booking's values. */
    private const ACTIONS = ['booking.status_change', 'booking.values.update'];

    /** A view is recorded at most once in this many minutes per person and type (the screen reloads on every filter). */
    private const VIEW_EVERY_MINUTES = 5;

    private const ROLES = ['approver', 'vice_approver'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    /** Records that an approver or vice approver opened the dashboard of these types; admins and the super admin are not recorded. @param array<int, int> $typeIds */
    public function recordView(AccessScope $scope, array $typeIds): void
    {
        if ($scope->isSuperAdmin() || $scope->isAdmin()) {
            return;
        }

        $actorId = $scope->user->actorId();

        foreach ($typeIds as $typeId) {
            $recent = AuditLog::query()
                ->where('action', self::VIEW)
                ->where('actor_id', $actorId)
                ->where('booking_type_id', $typeId)
                ->where('occurred_at', '>=', now()->subMinutes(self::VIEW_EVERY_MINUTES))
                ->exists();

            if (! $recent) {
                $this->audit->record([
                    'action' => self::VIEW,
                    'entity_type' => 'booking_type',
                    'entity_id' => $typeId,
                    'booking_type_id' => $typeId,
                    'unit_id' => $scope->user->unit_id,
                ], $scope->user);
            }
        }
    }

    /**
     * For the camp commander only: per role (approver, vice approver) the last view of the type's dashboard and the
     * last action on its bookings, by whoever holds that role on the type in the commander's unit today.
     * @return array<string, array{last_view: ?array, last_action: ?array}>|null
     */
    public function lastActivity(AccessScope $scope, int $typeId): ?array
    {
        if ($scope->isSuperAdmin() || ! $scope->isAdmin()) {
            return null;
        }

        $result = [];

        foreach (self::ROLES as $role) {
            $actorIds = User::permission("{$role}_{$typeId}")
                ->where('unit_id', $scope->user->unit_id)
                ->get()
                ->map(fn (User $user): ?string => $user->actorId())
                ->filter()
                ->values()
                ->all();

            if ($actorIds === []) {
                $result[$role] = ['last_view' => null, 'last_action' => null];
                continue;
            }

            // a fresh query each time: the view and the action are two separate lookups
            $base = fn () => AuditLog::query()
                ->where('booking_type_id', $typeId)
                ->whereIn('actor_id', $actorIds)
                ->where('result', 'success')
                ->orderByDesc('occurred_at');

            $view = $base()->where('action', self::VIEW)->first();
            $action = $base()->whereIn('action', self::ACTIONS)->first();

            $result[$role] = [
                'last_view' => $view ? ['name' => $view->actor_name, 'at' => $view->occurred_at] : null,
                'last_action' => $action ? [
                    'name' => $action->actor_name,
                    'at' => $action->occurred_at,
                    'action' => $action->action,
                    'serial' => $action->booking_serial,
                    'uuid' => $action->booking_serial !== null
                        ? Booking::query()->where('serial_number', $action->booking_serial)->value('uuid')
                        : null,
                    'status_id' => $action->after['status_id'] ?? null,
                ] : null,
            ];
        }

        return $result;
    }
}

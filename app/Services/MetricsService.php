<?php

namespace App\Services;

use App\Domain\Metrics\BottleneckAnalyzer;
use App\Enums\BookingStageEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use App\Models\SlaTarget;
use App\Models\User;
use App\Support\AccessScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/** Every figure is computed from stored data and limited to what the caller may see; the definitions travel with the numbers. */
class MetricsService
{
    public function __construct(
        private readonly StatusTimelineService $timeline,
    ) {
    }

    /**
     * What the screens open with: how many open bookings wait for the user, and the open ones per booking type and per type and stage.
     * Counted in the database, one row per (type, status, parked status) - not a full booking row each - and the rules run once per such group.
     * @return array{awaiting_me: array{count: int}, by_type: array<int, int>, by_type_stage: array<int, array<string, int>>}
     */
    public function overview(AccessScope $scope): array
    {
        $workflow = $scope->workflow();

        $groups = $scope->applyToBookings(Booking::query())
            ->whereNotIn('status_id', StatusBookingEnum::terminalIds())
            ->select('booking_type_id', 'status_id', 'parked_status_id', DB::raw('COUNT(*) AS total'))
            ->groupBy('booking_type_id', 'status_id', 'parked_status_id')
            ->get();

        $awaitingMe = 0;
        $byType = [];
        $byTypeStage = [];

        foreach ($groups as $group) {
            $bookingTypeId = (int) $group->booking_type_id;
            $total = (int) $group->total;
            // Whose turn it is and whether a hold is on follow Booking, as in the list rows.
            $position = $group->positionStatus();
            $stage = $workflow->stageOf($position, $bookingTypeId);

            $byType[$bookingTypeId] = ($byType[$bookingTypeId] ?? 0) + $total;
            $byTypeStage[$bookingTypeId][$stage->value] = ($byTypeStage[$bookingTypeId][$stage->value] ?? 0) + $total;

            if ($stage !== BookingStageEnum::CLOSED && $workflow->availableActions($position, $scope->actingPermissions, $bookingTypeId, $group->holdStatus()) !== []) {
                $awaitingMe += $total;
            }
        }

        return ['awaiting_me' => ['count' => $awaitingMe], 'by_type' => $byType, 'by_type_stage' => $byTypeStage];
    }

    /**
     * Where the bookings wait. Filters: days (window, 1 to 365, default 30), types (comma-separated booking type ids), unit (super admin only).
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function bottlenecks(AccessScope $scope, array $filters): array
    {
        $days = max(1, min(365, (int) ($filters['days'] ?? 30)));
        $from = now()->subDays($days)->getTimestamp();
        $previousFrom = $from - $days * 86400;
        $types = !empty($filters['types']) ? array_values(array_filter(array_map('intval', explode(',', (string) $filters['types'])))) : null;
        $unit = !empty($filters['unit']) && $scope->isSuperAdmin() ? (int) $filters['unit'] : null;

        // Open bookings and those that moved since the previous period began; no other has a stay in either period.
        $visible = $scope->applyToBookings(Booking::query())
            ->when($types, fn ($query) => $query->whereIn('booking_type_id', $types))
            ->when($unit, fn ($query) => $query->where('unit_id', $unit))
            ->openOrMovedSince(Carbon::createFromTimestamp($previousFrom))
            ->get();

        // Stays that ended before the window began do not count; those ended in the window before it are the previous period.
        $all = $this->timeline->intervals($visible, $previousFrom);
        $intervals = [];
        $previousIntervals = [];

        foreach ($all as $interval) {
            if ($interval['left_at'] !== null && $interval['left_at'] < $from) {
                $previousIntervals[] = $interval;

                continue;
            }

            $intervals[] = $interval;
        }

        $targets = $this->targets();
        $analyzer = new BottleneckAnalyzer($scope->workflow(), $targets);
        $stages = $analyzer->analyze($intervals, time());
        $previous = collect($analyzer->analyze($previousIntervals, $from))->pluck('median_hours', 'stage');

        foreach ($stages as &$stage) {
            $stage['prev_median_hours'] = $previous[$stage['stage']] ?? null;
        }

        unset($stage);

        $terminal = StatusBookingEnum::terminalIds();
        $bookingTypeNames = BookingType::query()->pluck('display_name', 'id');

        $byType = $visible
            ->reject(fn (Booking $booking): bool => in_array((int) $booking->status_id, $terminal, true))
            ->groupBy(fn (Booking $booking): string => $booking->booking_type_id . '|' . $scope->workflow()->stageOf($booking->positionStatus(), (int) $booking->booking_type_id)->value)
            ->map(function ($group, string $key) use ($bookingTypeNames): array {
                [$bookingTypeId, $stage] = explode('|', $key);

                return [
                    'booking_type_id' => (int) $bookingTypeId,
                    'label' => $bookingTypeNames[(int) $bookingTypeId] ?? $bookingTypeId,
                    'stage' => $stage,
                    'stage_label' => BookingStageEnum::from($stage)->label(),
                    'open' => $group->count(),
                ];
            })
            ->sortByDesc('open')
            ->values();

        // Load per person: moves made in the window and how fast (bookings are assigned to nobody in particular).
        $handlers = collect($intervals)
            ->filter(fn (array $interval): bool => $interval['left_at'] !== null && $interval['handler'])
            ->groupBy('handler')
            ->map(fn ($group, string $name): array => [
                'name' => $name,
                'transitions' => $group->count(),
                'median_hours' => BottleneckAnalyzer::percentile($group->map(fn (array $interval): float => ($interval['left_at'] - $interval['entered_at']) / 3600)->all(), 50),
            ])
            ->sortByDesc('transitions')
            ->values()
            ->take(12)
            ->all();

        // An infosec type has no vice approver or approver: its domain handler answers and closes at those stages.
        // ICT (20-25) runs on its own route, whose stages name VICE_APPROVER (the desk representative) as actingRole.
        $domainHandlers = BookingTypeHasRole::query()
            ->whereIn('role_id', [RoleEnum::INFOSEC_OFFICER->value])
            ->get(['booking_type_id', 'role_id'])
            ->mapWithKeys(fn (BookingTypeHasRole $link): array => [(int) $link->booking_type_id => RoleEnum::from((int) $link->role_id)]);

        // Pools: open bookings waiting for a role, shared by the holders of that role for the type in the unit.
        $pools = $byType
            ->filter(fn (array $row): bool => BookingStageEnum::from($row['stage'])->actingRole() !== null)
            ->map(function (array $row) use ($scope, $unit, $domainHandlers): array {
                $stage = BookingStageEnum::from($row['stage']);
                // The control-officer stage belongs to the type's own control officer (transportation 6, in-unit food 7).
                $role = $domainHandlers[$row['booking_type_id']]
                    ?? ($stage === BookingStageEnum::CONTROL_OFFICER ? RoleEnum::controlOfficerRoleForBookingType((int) $row['booking_type_id']) : null)
                    ?? $stage->actingRole();
                $permissionName = $role->toPermissionEnum()->keyForId($row['booking_type_id']);

                $holders = Permission::query()->where('name', $permissionName)->exists()
                    ? User::query()->permission($permissionName)
                        ->where('is_deleted', false)
                        ->when(!$scope->isSuperAdmin(), fn ($query) => $query->where('unit_id', $scope->user->unit_id))
                        ->when($unit, fn ($query) => $query->where('unit_id', $unit))
                        ->count()
                    : 0;

                return [...$row, 'role' => $role->getPermissionDisplayName(), 'holders' => $holders, 'per_holder' => $holders ? round($row['open'] / $holders, 1) : null];
            })
            ->values()
            ->all();

        $closed = $visible->filter(fn (Booking $booking): bool => in_array((int) $booking->status_id, $terminal, true) && $booking->statusSince()->getTimestamp() >= $from);
        $rate = fn (array $statusIds): ?float => $closed->count() ? round(100 * $closed->whereIn('status_id', $statusIds)->count() / $closed->count(), 1) : null;

        return [
            'window_days' => $days,
            'stages' => $stages,
            'by_type' => $byType->all(),
            'handlers' => $handlers,
            'pools' => $pools,
            'rates' => [
                'closed' => $closed->count(),
                // Completed, or the successful end of a type's own route.
                'completed_pct' => $rate($scope->workflow()->completedStatusIds()),
                'rejected_pct' => $rate([StatusBookingEnum::REJECTED->value]),
                'canceled_by_user_pct' => $rate([StatusBookingEnum::CANCELED_BY_USER->value]),
                'canceled_by_approver_pct' => $rate([StatusBookingEnum::CANCELED_BY_APPROVER->value, StatusBookingEnum::CANCELED_BY_BARBER->value, StatusBookingEnum::CANCELED_BY_CHANGING_TIME->value]),
            ],
            'definitions' => 'זמן בשלב = מהכניסה לסטטוס ועד המעבר הבא ביומן הפעולות. חציון ו-P90 על מעברים שהושלמו בחלון. חומרה רק לשלבים שהוגדר להם יעד. גורם מטפל = מי שביצע בפועל את המעבר הבא.',
        ];
    }

    /** @return array<int, array{target_hours: float, warn_ratio: float}> by booking type id */
    private function targets(): array
    {
        return SlaTarget::query()
            ->where('stage', SlaTarget::STAGE_TOTAL)
            ->get()
            ->mapWithKeys(fn (SlaTarget $target): array => [(int) $target->booking_type_id => ['target_hours' => (float) $target->target_hours, 'warn_ratio' => (float) $target->warn_ratio]])
            ->all();
    }
}

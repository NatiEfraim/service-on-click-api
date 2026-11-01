<?php

namespace App\Services;

use App\Domain\Metrics\BottleneckAnalyzer;
use App\Enums\StatusBookingEnum;
use App\Exceptions\DomainError;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\SlaTarget;
use App\Models\SyncConflict;
use App\Support\AccessScope;

/**
 * The main screen (a tile per booking type, with what is open by stage) and the dashboard of a booking type.
 * The super admin and the admin (camp commander) see the tiles and any of their booking types; the approver (domain commander) only his own
 * booking types' dashboard; a handler (vice approver, control officer) none.
 */
class DashboardService
{
    public function __construct(
        private readonly BookingQueryService $bookings,
        private readonly StatusTimelineService $timeline,
    ) {
    }

    /** @return array{domains: array<int, array<string, mixed>>, by_type_stage: array<int, array<string, int>>} @throws DomainError 403 */
    public function home(AccessScope $scope): array
    {
        if (!$scope->isSuperAdmin() && !$scope->isAdmin()) {
            throw new DomainError('המסך הראשי עם כל התחומים זמין למנהל מערכת ולמפקד מחנה', 403);
        }

        $typeIds = $scope->dashboardBookingTypeIds();
        $targets = $this->targets();
        $terminal = StatusBookingEnum::terminalIds();

        $base = $scope->applyToBookings(Booking::query())->whereIn('booking_type_id', $typeIds);

        // Counted by the database: nothing but the figures is read.
        $totals = (clone $base)->selectRaw('booking_type_id, COUNT(*) AS total')->groupBy('booking_type_id')->pluck('total', 'booking_type_id');
        $openByStatus = (clone $base)
            ->whereNotIn('status_id', $terminal)
            ->selectRaw('booking_type_id, status_id, COUNT(*) AS total')
            ->groupBy('booking_type_id', 'status_id')
            ->get();

        $types = BookingType::query()->whereIn('id', $typeIds)->get(['id', 'display_name', 'icon'])->keyBy('id');
        $groups = BookingType::query()->whereIn('id', $typeIds)->with('groupBookingTypes:id,name,display_name')->get()
            ->mapWithKeys(fn (BookingType $type): array => [$type->id => $type->groupBookingTypes->first()?->display_name]);

        $domains = collect($typeIds)->map(function (int $typeId) use ($base, $openByStatus, $totals, $types, $groups, $targets, $terminal): array {
            $target = $targets[$typeId]['target_hours'] ?? null;

            return [
                'booking_type_id' => $typeId,
                'name' => $types[$typeId]->display_name ?? '',
                'icon' => $types[$typeId]->icon ?? null,
                'group' => $groups[$typeId] ?? null,
                'open' => (int) $openByStatus->where('booking_type_id', $typeId)->sum('total'),
                'total' => (int) ($totals[$typeId] ?? 0),
                'target_hours' => $target,
                'overdue' => $target ? (clone $base)
                    ->where('booking_type_id', $typeId)
                    ->whereNotIn('status_id', $terminal)
                    ->where('created_at', '<', now()->subSeconds((int) round($target * 3600)))
                    ->count() : null,
            ];
        })->values()->all();

        $byTypeStage = [];

        foreach ($openByStatus as $group) {
            $stage = $scope->workflow()->stageOf(StatusBookingEnum::from((int) $group->status_id), (int) $group->booking_type_id)->value;
            $byTypeStage[(int) $group->booking_type_id][$stage] = ($byTypeStage[(int) $group->booking_type_id][$stage] ?? 0) + (int) $group->total;
        }

        return ['domains' => $domains, 'by_type_stage' => $byTypeStage];
    }

    /**
     * The dashboard of one or more booking types: totals, handling times, where the open bookings wait, the trend and the oldest bookings.
     * @param array<int, int|string> $requestedTypeIds booking types asked for (cut down to those the user may open; empty = all of them)
     * @return array<string, mixed>
     * @throws DomainError 403
     */
    public function domain(AccessScope $scope, array $requestedTypeIds, int $days = 30): array
    {
        if (!$scope->can('dashboard.view')) {
            throw new DomainError('הדשבורד אינו זמין בתפקידך', 403);
        }

        $allowed = $scope->dashboardBookingTypeIds();
        $typeIds = $requestedTypeIds ? array_values(array_intersect(array_map('intval', $requestedTypeIds), $allowed)) : $allowed;

        if ($typeIds === []) {
            throw new DomainError('אין לך הרשאה לדשבורד של תחום זה', 403);
        }

        $days = max(1, min(365, $days));
        $from = now()->subDays($days);
        $terminal = StatusBookingEnum::terminalIds();
        $targets = $this->targets();

        $base = $scope->applyToBookings(Booking::query())->whereIn('booking_type_id', $typeIds);

        // Totals over the whole history are counted by the database; the bookings read are the open ones and those that moved in the window
        // (the closed ones before it are in no figure but the two counts).
        $totalAll = (clone $base)->count();
        $closedAll = (clone $base)->whereIn('status_id', $terminal)->count();

        $all = (clone $base)
            ->openOrMovedSince($from)
            ->with(['user:id,uuid,full_name,personal_id,personal_number', 'bookingType:id,display_name', 'status:id,display_name'])
            ->get();

        $open = $all->reject(fn (Booking $booking): bool => in_array((int) $booking->status_id, $terminal, true));
        $closed = $all->filter(fn (Booking $booking): bool => in_array((int) $booking->status_id, $terminal, true));
        $closedInWindow = $closed->filter(fn (Booking $booking): bool => $booking->statusSince()->gte($from));

        $handlingHours = $closedInWindow
            ->map(fn (Booking $booking): float => $booking->openedAt()->diffInMinutes($booking->statusSince(), true) / 60)
            ->values()
            ->all();

        // (A subquery, not the list of ids: a long list does not fit in one statement.)
        $conflicts = SyncConflict::query()
            ->whereIn('booking_id', (clone $base)->whereNotIn('status_id', $terminal)->select('id'))
            ->where('state', SyncConflict::STATE_OPEN)
            ->pluck('booking_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();

        $rows = $open->map(fn (Booking $booking): array => $this->bookings->row($booking, $scope, $conflicts));

        $stages = (new BottleneckAnalyzer($scope->workflow(), array_intersect_key($targets, array_flip($typeIds))))
            ->analyze($this->timeline->intervals($all, $from->getTimestamp()), time());

        $trend = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();

            $trend[] = [
                'day' => $day,
                'opened' => $all->filter(fn (Booking $booking): bool => $booking->openedAt()->toDateString() === $day)->count(),
                'closed' => $closed->filter(fn (Booking $booking): bool => $booking->statusSince()->toDateString() === $day)->count(),
            ];
        }

        $rate = fn (array $statusIds): ?float => $closedInWindow->count()
            ? round(100 * $closedInWindow->whereIn('status_id', $statusIds)->count() / $closedInWindow->count(), 1)
            : null;

        return [
            'booking_type_ids' => $typeIds,
            'window_days' => $days,
            'target' => count($typeIds) === 1 ? ($targets[$typeIds[0]] ?? null) : null,
            'totals' => [
                'open' => $open->count(),
                'closed' => $closedAll,
                'closed_in_window' => $closedInWindow->count(),
                'total' => $totalAll,
                'overdue' => $rows->where('overdue', true)->count(),
                'needs_decision' => $rows->where('has_conflict', true)->count(),
                'stale' => $rows->filter(fn (array $row): bool => $row['stage_age_hours'] > 24 * 7)->count(),
            ],
            'handling' => [
                'median_hours' => BottleneckAnalyzer::percentile($handlingHours, 50),
                'p90_hours' => BottleneckAnalyzer::percentile($handlingHours, 90),
                'avg_hours' => $handlingHours ? round(array_sum($handlingHours) / count($handlingHours), 1) : null,
                'sample' => count($handlingHours),
            ],
            'by_stage' => $rows->countBy('stage')->all(),
            'stages' => $stages,
            'rates' => [
                'closed' => $closedInWindow->count(),
                'completed_pct' => $rate([StatusBookingEnum::COMPLETED->value]),
                'rejected_pct' => $rate([StatusBookingEnum::REJECTED->value]),
                'canceled_by_user_pct' => $rate([StatusBookingEnum::CANCELED_BY_USER->value]),
                'canceled_by_approver_pct' => $rate([StatusBookingEnum::CANCELED_BY_APPROVER->value, StatusBookingEnum::CANCELED_BY_BARBER->value, StatusBookingEnum::CANCELED_BY_CHANGING_TIME->value]),
            ],
            'oldest' => $rows->sortByDesc('total_age_hours')->take(6)->values()->all(),
            'overdue_serials' => $rows->where('overdue', true)->pluck('serial_number')->values()->all(),
            'trend' => $trend,
            'definitions' => 'יעד טיפול = זמן כולל מפתיחת הקריאה ועד סגירתה, אחד לכל תחום. קריאה חורגת כשגילה עובר את היעד. בניתוח השלבים נספרות הקריאות החורגות לפי השלב שבו הן נמצאות עכשיו; זמני שלב מחושבים מיומן הפעולות.',
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

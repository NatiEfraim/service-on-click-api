<?php

namespace App\Services;

use App\Domain\Metrics\BottleneckAnalyzer;
use App\Enums\BookingStageEnum;
use App\Enums\StatusBookingEnum;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\SlaTarget;
use App\Models\SyncConflict;
use App\Models\User;
use App\Support\AccessScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/** Every figure is computed from stored data and limited to what the caller may see; the definitions travel with the numbers. */
class MetricsService
{
    /** A booking that has not moved for this many days is "stale". */
    private const STALE_DAYS = 7;

    public function __construct(
        private readonly BookingQueryService $bookings,
        private readonly StatusTimelineService $timeline,
    ) {
    }

    /** The logged-in user's own dashboard: what is open, what waits for them, what is overdue. @return array<string, mixed> */
    public function overview(AccessScope $scope): array
    {
        $base = $scope->applyToBookings(Booking::query());

        $open = (clone $base)
            ->whereNotIn('status_id', StatusBookingEnum::terminalIds())
            ->with(['user:id,uuid,full_name,personal_id,personal_number', 'bookingType:id,display_name', 'status:id,display_name'])
            ->get();

        // A subquery, not an id list: a long list does not fit in one statement.
        $conflicts = SyncConflict::query()
            ->whereIn('booking_id', (clone $base)->whereNotIn('status_id', StatusBookingEnum::terminalIds())->select('id'))
            ->where('state', SyncConflict::STATE_OPEN)
            ->pluck('booking_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();

        $rows = $open->map(fn (Booking $booking): array => $this->bookings->row($booking, $scope, $conflicts));
        $pick = fn ($collection): array => ['count' => $collection->count(), 'serials' => $collection->pluck('serial_number')->take(500)->values()->all()];

        return [
            'open_total' => $open->count(),
            'total' => (clone $base)->count(),
            'awaiting_me' => $pick($rows->filter(fn (array $row): bool => $row['actions'] !== [])),
            'overdue' => $pick($rows->filter(fn (array $row): bool => $row['overdue'])),
            // The mobile app changed the status in parallel: a person must decide.
            'needs_decision' => $pick($rows->filter(fn (array $row): bool => $row['has_conflict'])),
            'stale' => $pick($rows->filter(fn (array $row): bool => $row['stage_age_hours'] > 24 * self::STALE_DAYS)),
            'by_stage' => $rows->countBy('stage')->all(),
            'by_type' => $rows->countBy('booking_type_id')->all(),
            'by_type_stage' => $rows->groupBy('booking_type_id')->map(fn ($group) => $group->countBy('stage'))->all(),
            'by_status' => (clone $base)->select('status_id', DB::raw('COUNT(*) AS total'))->groupBy('status_id')->pluck('total', 'status_id')->all(),
            'my_top' => $rows->filter(fn (array $row): bool => $row['actions'] !== [])->sortByDesc('stage_age_hours')->take(6)->values()->all(),
            // For view-only roles (the camp commander).
            'oldest_top' => $rows->sortByDesc('stage_age_hours')->take(6)->values()->all(),
            'bottlenecks' => array_slice($this->bottlenecks($scope, [])['stages'], 0, 4),
            'trend' => $this->trend($scope, 30),
            'definitions' => [
                'open_total' => 'הזמנות שאינן בסטטוס סופי (נדחו, הסתיימו, בוטלו), בתחומים וביחידה שבאחריותך',
                'awaiting_me' => 'הזמנות פתוחות שיש לך לגביהן לפחות פעולה אחת לפי חוקי התהליך',
                'overdue' => 'הזמנות שגילן מפתיחתן עובר את יעד הטיפול של התחום',
                'stale' => 'הזמנות פתוחות שלא זזו יותר מ-' . self::STALE_DAYS . ' ימים',
            ],
        ];
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

        // Pools: open bookings waiting for a role, shared by the holders of that role for the type in the unit.
        $pools = $byType
            ->filter(fn (array $row): bool => BookingStageEnum::from($row['stage'])->actingRole() !== null)
            ->map(function (array $row) use ($scope, $unit): array {
                $role = BookingStageEnum::from($row['stage'])->actingRole();
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

    /** Bookings opened and closed per day over the last $days days. @return array<int, array{day: string, opened: int, closed: int}> */
    public function trend(AccessScope $scope, int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $opened = $scope->applyToBookings(Booking::query())
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) AS day'), DB::raw('COUNT(*) AS total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        $closed = ApplicationLog::query()
            ->whereIn('booking_id', $scope->applyToBookings(Booking::query())->select('id'))
            ->whereIn('status_id', StatusBookingEnum::terminalIds())
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) AS day'), DB::raw('COUNT(*) AS total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $trend[] = ['day' => $day, 'opened' => (int) ($opened[$day] ?? 0), 'closed' => (int) ($closed[$day] ?? 0)];
        }

        return $trend;
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

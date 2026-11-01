<?php

namespace App\Domain\Metrics;

use App\Domain\Workflow\Workflow;
use App\Enums\BookingStageEnum;
use App\Enums\StatusBookingEnum;

/**
 * Where bookings wait, stage by stage, measured against the HANDLING TARGET of each booking type: a type has one target, the total time from
 * a booking's opening to its closing. A booking is overdue when its total age passes the target of its type; a stage is a bottleneck when
 * overdue bookings pile up in it. No database access.
 *
 * An interval is a booking's stay in one status:
 *   ['booking_id' => serial, 'booking_type_id', 'status_id', 'entered_at' (unix), 'left_at' (unix, null while it is still there),
 *    'created_at' (unix: when the booking was opened), 'handler' => name of whoever moved it on (optional)]
 *  - open (left_at null): stage age = now - entered_at, total age = now - created_at
 *  - closed: duration = left_at - entered_at; its share of the lead time = the stage's durations / all stages' durations
 *  - severity needs a target: critical = (25% or more of the waiting bookings overdue AND at least 2) OR 5 or more overdue; warning = any
 *    overdue or near the target; ok; no_target when none of the stage's booking types has a target
 */
final class BottleneckAnalyzer
{
    /** @param array<int, array{target_hours: float, warn_ratio?: float}> $targets by booking type id */
    public function __construct(private readonly Workflow $workflow, private readonly array $targets = [])
    {
    }

    /**
     * @param array<int, array<string, mixed>> $intervals
     * @return array<int, array<string, mixed>> one entry per stage that has an interval, the most severe first
     */
    public function analyze(array $intervals, int $now): array
    {
        $byStage = [];
        $totalDuration = 0.0;

        foreach ($intervals as $interval) {
            $bookingTypeId = (int) $interval['booking_type_id'];
            $status = StatusBookingEnum::tryFrom((int) $interval['status_id']);

            if ($status === null) {
                continue;
            }

            $stage = $this->workflow->stageOf($status, $bookingTypeId);

            if ($stage === BookingStageEnum::CLOSED) {
                continue;
            }

            $key = $stage->value;
            $byStage[$key] ??= ['stage' => $key, 'label' => $stage->label(), 'wait_kind' => $stage->waitKind(), 'open' => [], 'durations' => [], 'handlers' => [], 'targets' => []];

            $target = $this->targets[$bookingTypeId] ?? null;
            $byStage[$key]['targets'][$bookingTypeId] = $target ? (float) $target['target_hours'] : null;

            if ($interval['left_at'] === null) {
                $byStage[$key]['open'][] = [
                    'booking_id' => $interval['booking_id'],
                    'age' => max(0, $now - (int) $interval['entered_at']) / 3600,
                    'total' => max(0, $now - (int) ($interval['created_at'] ?? $interval['entered_at'])) / 3600,
                    'target' => $target['target_hours'] ?? null,
                    'warn' => $target['warn_ratio'] ?? 0.8,
                ];

                continue;
            }

            $duration = max(0, (int) $interval['left_at'] - (int) $interval['entered_at']) / 3600;
            $byStage[$key]['durations'][] = $duration;
            $totalDuration += $duration;

            if (!empty($interval['handler'])) {
                $byStage[$key]['handlers'][$interval['handler']] = ($byStage[$key]['handlers'][$interval['handler']] ?? 0) + 1;
            }
        }

        $out = [];

        foreach ($byStage as $key => $stage) {
            $withTarget = array_filter($stage['open'], fn (array $open): bool => $open['target'] !== null);
            $over = array_values(array_filter($withTarget, fn (array $open): bool => $open['total'] > $open['target']));
            $near = array_filter($withTarget, fn (array $open): bool => $open['total'] > $open['target'] * $open['warn'] && $open['total'] <= $open['target']);
            $domains = $stage['targets'];
            $hasTarget = array_filter($domains, fn ($hours): bool => $hours !== null) !== [];
            $sum = array_sum($stage['durations']);
            $ages = array_column($stage['open'], 'age');

            arsort($stage['handlers']);

            $out[] = [
                'stage' => $key,
                'label' => $stage['label'],
                'wait_kind' => $stage['wait_kind'],
                'open_count' => count($stage['open']),
                'open_booking_ids' => array_column($stage['open'], 'booking_id'),
                'overdue_booking_ids' => array_column($over, 'booking_id'),
                'oldest_open_hours' => $ages ? round(max($ages), 1) : null,
                'avg_hours' => $stage['durations'] ? round($sum / count($stage['durations']), 1) : null,
                'median_hours' => self::percentile($stage['durations'], 50),
                'p90_hours' => self::percentile($stage['durations'], 90),
                'sample_size' => count($stage['durations']),
                'share_of_lead_time' => $totalDuration > 0 ? round($sum / $totalDuration, 3) : null,
                // The target of the booking type; null when the stage mixes booking types.
                'target_hours' => count($domains) === 1 ? reset($domains) : null,
                'breached_open' => $hasTarget ? count($over) : null,
                'near_breach_open' => $hasTarget ? count($near) : null,
                'severity' => self::severity($hasTarget, count($withTarget), count($over), count($near)),
                'top_handlers' => array_slice($stage['handlers'], 0, 5, true),
            ];
        }

        $rank = ['critical' => 3, 'warning' => 2, 'ok' => 1, 'no_target' => 0];

        usort($out, fn (array $a, array $b): int => $rank[$b['severity']] <=> $rank[$a['severity']] ?: $b['open_count'] <=> $a['open_count']);

        return $out;
    }

    /** The $p-th percentile (linear interpolation) of a list of numbers; null for an empty list. @param array<int, float|int> $values */
    public static function percentile(array $values, int $p): ?float
    {
        if (!$values) {
            return null;
        }

        sort($values);

        $index = ($p / 100) * (count($values) - 1);
        $low = (int) floor($index);
        $high = (int) ceil($index);

        return round($values[$low] + ($values[$high] - $values[$low]) * ($index - $low), 1);
    }

    private static function severity(bool $hasTarget, int $open, int $over, int $near): string
    {
        if (!$hasTarget) {
            return 'no_target';
        }

        if ($open === 0) {
            return 'ok';
        }

        return match (true) {
            ($over / $open >= 0.25 && $over >= 2) || $over >= 5 => 'critical',
            $over > 0 || $near > 0 => 'warning',
            default => 'ok',
        };
    }
}

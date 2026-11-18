<?php

namespace App\Services;

use App\Domain\Workflow\Workflow;
use App\Enums\StatusBookingEnum;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\Status;

/**
 * The progress bar of a request of a route type: the submission, then every step of the route (done / current / upcoming), and the refusal
 * when the request was refused. Times come from the timeline (the LATEST row of each status, so a request moved back shows its new times).
 * Null for a type without a route.
 */
class ProgressService
{
    public const STATE_DONE = 'done';
    public const STATE_CURRENT = 'current';
    public const STATE_UPCOMING = 'upcoming';
    public const STATE_REJECTED = 'rejected';

    public function __construct(private readonly Workflow $workflow)
    {
    }

    /** @return ?array<int, array{key: string, status_id?: int, label: string, state: string, at?: ?string}> */
    public function progress(Booking $booking): ?array
    {
        $route = $this->workflow->routeFor((int) $booking->booking_type_id);

        if ($route === null) {
            return null;
        }

        $steps = $route->steps();
        $labels = Status::query()->whereIn('id', [...$steps, StatusBookingEnum::REJECTED->value])->pluck('display_name', 'id');
        $at = $this->latestEntryPerStatus($booking);
        $currentStatusId = (int) $booking->status_id;
        $rejected = $currentStatusId === StatusBookingEnum::REJECTED->value;

        // How far the request got: its current step, or - when refused - the furthest step it was ever in.
        $reached = array_search($currentStatusId, $steps, true);

        if ($reached === false) {
            $reached = 0;

            if ($rejected) {
                foreach ($steps as $index => $statusId) {
                    if (isset($at[$statusId])) {
                        $reached = $index;
                    }
                }
            }
        }

        $rows = [[
            'key' => 'submitted',
            'label' => 'הוגשה',
            'state' => self::STATE_DONE,
            'at' => $booking->openedAt()->toIso8601String(),
        ]];

        foreach ($steps as $index => $statusId) {
            $status = StatusBookingEnum::from($statusId);

            if ($rejected) {
                $state = $index <= $reached ? self::STATE_DONE : self::STATE_UPCOMING;
            } elseif ($index < $reached || ($index === $reached && $statusId === $route->successStatus() && $currentStatusId === $statusId)) {
                $state = self::STATE_DONE;
            } elseif ($index === $reached) {
                $state = self::STATE_CURRENT;
            } else {
                $state = self::STATE_UPCOMING;
            }

            $when = $at[$statusId] ?? ($currentStatusId === $statusId ? $booking->statusSince()->toIso8601String() : null);

            $rows[] = [
                'key' => $status->getStatusName(),
                'status_id' => $statusId,
                'label' => $labels[$statusId] ?? $status->getDashboardDisplayName(),
                'state' => $state,
                'at' => $state === self::STATE_UPCOMING ? null : $when,
            ];
        }

        if ($rejected) {
            $rows[] = [
                'key' => 'rejected',
                'status_id' => StatusBookingEnum::REJECTED->value,
                'label' => $labels[StatusBookingEnum::REJECTED->value] ?? StatusBookingEnum::REJECTED->getDashboardDisplayName(),
                'state' => self::STATE_REJECTED,
                'at' => $at[StatusBookingEnum::REJECTED->value] ?? $booking->statusSince()->toIso8601String(),
            ];
        }

        return $rows;
    }

    /** @return array<int, string> status id => ISO time of the latest timeline row that set it */
    private function latestEntryPerStatus(Booking $booking): array
    {
        $logs = $booking->relationLoaded('applicationLog')
            ? $booking->applicationLog->sortBy([['created_at', 'asc'], ['id', 'asc']])
            : ApplicationLog::query()->where('booking_id', $booking->id)->orderBy('created_at')->orderBy('id')->get();

        $at = [];

        foreach ($logs as $log) {
            if ($log->status_id !== null && $log->created_at !== null) {
                $at[(int) $log->status_id] = $log->created_at->toIso8601String();
            }
        }

        return $at;
    }
}

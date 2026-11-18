<?php

namespace App\Support;

use App\Domain\Workflow\Transition;
use App\Domain\Workflow\TypeRoute;
use Carbon\CarbonImmutable;

/** The moment a route type's automatic "due" transition waits for (bookings.stage_due_at), from the handler fields' date and time. */
final class DueMoment
{
    /**
     * @param int $statusId the status the booking is (or is about to be) in
     * @param array<string, mixed> $valuesByFieldName the handler values, by field name ("Y-m-d" date, "H:i" time)
     * @return ?CarbonImmutable UTC; null when the status has no due transition or the date / time are not both there
     */
    public static function for(TypeRoute $route, int $statusId, array $valuesByFieldName, ?string $timezone = null): ?CarbonImmutable
    {
        if (!self::statusHasDueTransition($route, $statusId)) {
            return null;
        }

        $fields = $route->dueFields();

        if ($fields === null) {
            return null;
        }

        $date = trim((string) ($valuesByFieldName[$fields['date']] ?? ''));
        $time = trim((string) ($valuesByFieldName[$fields['time']] ?? ''));

        if ($date === '' || $time === '') {
            return null;
        }

        try {
            $moment = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone ?: config('workflow.clearance.timezone', 'Asia/Jerusalem'));
        } catch (\Throwable) {
            return null;
        }

        return $moment === false ? null : $moment->utc();
    }

    /** Whether a booking in this status is moved on by the scheduler once its moment has passed. */
    public static function statusHasDueTransition(TypeRoute $route, int $statusId): bool
    {
        foreach ($route->transitions() as $transition) {
            if ($transition->auto === Transition::AUTO_DUE && $transition->from === $statusId) {
                return true;
            }
        }

        return false;
    }
}

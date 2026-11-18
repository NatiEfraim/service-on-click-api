<?php

namespace App\Services;

use App\Enums\ActionTypeEnum;
use App\Enums\BookingTypeEnum;
use App\Enums\StatusBookingEnum;
use App\Models\ApplicationLog;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Applies an internal status decision: moves the booking, marks it unsent (is_status_exported=0 for the next r2b file, holds included -
 * not for a web-only type, which the mobile app never sees), logs a timeline row, frees a barber slot. Caller owns the transaction and audit.
 */
class BookingStatusRecorder
{
    /**
     * @param ?User $actor the person who decided; null for a move the system made on its own (a scheduled transition of a route type)
     * @param string $source internal (a person decided) or system (the system acted, for a person or on its own)
     * @param ?ActionTypeEnum $actionType the timeline action; null = the status's own (StatusBookingEnum::getActionType)
     * @param array<string, mixed> $attributes other booking columns to set in the same write (e.g. stage_due_at)
     * @return string the correlation id tying the log row, audit row and exported file together
     */
    public function apply(
        Booking $booking,
        StatusBookingEnum $target,
        ?User $actor,
        string $source = 'internal',
        ?string $correlationId = null,
        ?ActionTypeEnum $actionType = null,
        array $attributes = [],
    ): string {
        $correlationId ??= (string) Str::uuid();
        $now = now();
        $webOnly = BookingTypeEnum::isWebOnlyId((int) $booking->booking_type_id);

        $booking->forceFill([
            ...$attributes,
            'status_id' => $target->value,
            // A hold does not move the booking: it stays parked at the status it was put on hold from, and leaving the hold clears that. Worked
            // out from the status the booking still has, here, before the new one is set.
            'parked_status_id' => $booking->parkedStatusFor($target),
            // Whatever the log row calls it, the status now differs from the mobile app's: a decision made here. A web-only type keeps
            // "system" when the system moved it, so the screens can tell the two apart.
            'status_source' => $webOnly && $source === 'system' ? 'system' : 'internal',
            'status_changed_at' => $now,
            'version' => (int) $booking->version + 1,
            // A web-only type has nothing to send; every other type waits for the next status file.
            'is_status_exported' => $webOnly ? (bool) $booking->is_status_exported : false,
        ])->save();

        $this->log($booking, $target, $actor, $source, $correlationId, $actionType);

        if ($target->freesBarberSlot()) {
            BarberBooking::query()->where('booking_id', $booking->id)->update(['is_deleted' => true]);
        }

        return $correlationId;
    }

    /** Adds the booking's timeline entry for a status it moved into. */
    public function log(Booking $booking, StatusBookingEnum $status, ?User $actor, string $source, string $correlationId, ?ActionTypeEnum $actionType = null): ApplicationLog
    {
        return ApplicationLog::query()->create([
            'booking_id' => $booking->id,
            'action_type_id' => ($actionType ?? $status->getActionType())->value,
            'status_id' => $status->value,
            'source' => $source,
            'correlation_id' => $correlationId,
            'user_id' => $actor?->id,
            // Not an echo of an entry from the mobile app.
            'is_exported' => false,
        ]);
    }
}

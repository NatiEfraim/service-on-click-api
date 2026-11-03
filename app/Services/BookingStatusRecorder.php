<?php

namespace App\Services;

use App\Enums\StatusBookingEnum;
use App\Models\ApplicationLog;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Everything that follows when a decision made here puts a booking into a new status, so approvals, date-blocking cancellations and
 * conflicts settled in our favour leave the same traces: the booking moves and is marked unsent to the mobile app (is_status_exported = 0, so the
 * next round of the r2b booking status file carries it - a hold, 8 / 9 / 10, included), a timeline row is logged and a barber slot is freed.
 * The caller opens the transaction and writes the audit row.
 */
class BookingStatusRecorder
{
    /**
     * @param string $source internal (a person decided) or system (the system acted for a person)
     * @return string the correlation id tying the log row, audit row and exported file together
     */
    public function apply(Booking $booking, StatusBookingEnum $target, User $actor, string $source = 'internal', ?string $correlationId = null): string
    {
        $correlationId ??= (string) Str::uuid();
        $now = now();

        $booking->forceFill([
            'status_id' => $target->value,
            // A hold does not move the booking: it stays parked at the status it was put on hold from, and leaving the hold clears that. Worked
            // out from the status the booking still has, here, before the new one is set.
            'parked_status_id' => $booking->parkedStatusFor($target),
            // Whatever the log row calls it, the status now differs from the mobile app's: a decision made here.
            'status_source' => 'internal',
            'status_changed_at' => $now,
            'version' => (int) $booking->version + 1,
            'is_status_exported' => false,
        ])->save();

        $this->log($booking, $target, $actor, $source, $correlationId);

        if ($target->freesBarberSlot()) {
            BarberBooking::query()->where('booking_id', $booking->id)->update(['is_deleted' => true]);
        }

        return $correlationId;
    }

    /** Adds the booking's timeline entry for a status it moved into. */
    public function log(Booking $booking, StatusBookingEnum $status, User $actor, string $source, string $correlationId): ApplicationLog
    {
        return ApplicationLog::query()->create([
            'booking_id' => $booking->id,
            'action_type_id' => $status->getActionType()->value,
            'status_id' => $status->value,
            'source' => $source,
            'correlation_id' => $correlationId,
            'user_id' => $actor->id,
            // Not an echo of an entry from the mobile app.
            'is_exported' => false,
        ]);
    }
}

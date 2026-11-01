<?php

namespace App\Services;

use App\Enums\StatusBookingEnum;
use App\Models\ApplicationLog;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Everything that follows when a decision made in this system puts a booking into a new status - in one place, so a status change by an
 * approver, a cancellation because a date was blocked and a conflict settled in favour of this system all leave the same traces:
 *   - the booking moves (status, version, status_changed_at) and is marked as not yet sent back to the mobile app (is_status_exported = false),
 *   - an application_logs row records who moved it where (the booking's timeline),
 *   - a barber booking gives its slot back when the new status frees it.
 * The caller opens the transaction and writes the audit row.
 */
class BookingStatusRecorder
{
    /**
     * @param string $source internal (a person decided) or system (the system acted for a person)
     * @return string the correlation id that ties the log row, the audit row and the exported file together
     */
    public function apply(Booking $booking, StatusBookingEnum $target, User $actor, string $source = 'internal', ?string $correlationId = null): string
    {
        $correlationId ??= (string) Str::uuid();
        $now = now();

        $booking->forceFill([
            'status_id' => $target->value,
            // Whatever the log row calls it, the booking's status now differs from what the mobile app has: a decision made here.
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

    /** Add the booking's timeline entry for a status it moved into. */
    public function log(Booking $booking, StatusBookingEnum $status, User $actor, string $source, string $correlationId): ApplicationLog
    {
        return ApplicationLog::query()->create([
            'booking_id' => $booking->id,
            'action_type_id' => $status->getActionType()->value,
            'status_id' => $status->value,
            'source' => $source,
            'correlation_id' => $correlationId,
            'user_id' => $actor->id,
            // The mobile app does not have this entry: it is not an echo of one of its own.
            'is_exported' => false,
        ]);
    }
}

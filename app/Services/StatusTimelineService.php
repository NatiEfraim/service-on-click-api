<?php

namespace App\Services;

use App\Models\ApplicationLog;
use App\Models\Booking;
use Illuminate\Support\Collection;

/** How long bookings stayed in each status; older bookings (before the log carried statuses) only know their current status open interval. */
class StatusTimelineService
{
    /**
     * @param Collection<int, Booking> $bookings
     * @param ?int $leftSince only intervals still open at this unix time or ended after it; null keeps all
     * @return array<int, array{booking_id: int, booking_type_id: int, status_id: int, entered_at: int, left_at: ?int, created_at: int, handler: ?string}>
     */
    public function intervals(Collection $bookings, ?int $leftSince = null): array
    {
        if ($bookings->isEmpty()) {
            return [];
        }

        $intervals = [];

        // Logs are read a few thousand bookings at a time: a statement cannot hold an unlimited id list,
        // and the logs of finished bookings need not stay in memory.
        foreach ($bookings->chunk(2000) as $chunk) {
            $logs = ApplicationLog::query()
                ->whereIn('booking_id', $chunk->modelKeys())
                ->whereNotNull('status_id')
                ->with('user:id,full_name')
                ->orderBy('booking_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'booking_id', 'status_id', 'user_id', 'created_at'])
                ->groupBy('booking_id');

            foreach ($chunk as $booking) {
                // [status id, entered at (unix), name of whoever put the booking into the status]
                $entries = $logs->get($booking->id, collect())
                    ->map(fn (ApplicationLog $log): array => [(int) $log->status_id, $log->created_at->getTimestamp(), $log->user?->full_name])
                    ->values()
                    ->all();

                if ($entries === [] || end($entries)[0] !== (int) $booking->status_id) {
                    $entries[] = [(int) $booking->status_id, $booking->statusSince()->getTimestamp(), null];
                }

                $openedAt = $booking->openedAt()->getTimestamp();

                foreach ($entries as $index => [$statusId, $enteredAt]) {
                    $next = $entries[$index + 1] ?? null;
                    $leftAt = $next ? $next[1] : null;

                    if ($leftAt !== null && $leftSince !== null && $leftAt < $leftSince) {
                        continue;
                    }

                    $intervals[] = [
                        'booking_id' => (int) $booking->serial_number,
                        'booking_type_id' => (int) $booking->booking_type_id,
                        'status_id' => $statusId,
                        'entered_at' => $enteredAt,
                        'left_at' => $leftAt,
                        'created_at' => $openedAt,
                        // Whoever moved the booking on: the person behind the next entry.
                        'handler' => $next[2] ?? null,
                    ];
                }
            }
        }

        return $intervals;
    }
}

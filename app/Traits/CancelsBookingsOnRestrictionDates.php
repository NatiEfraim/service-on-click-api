<?php

namespace App\Traits;

use App\Enums\BookingTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\StatusBookingEnum;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Services\AuditService;
use App\Services\BookingStatusRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cancel bookings on dates newly added to a booking type's restriction list (the type's cancel status; the web system sends no SMS).
 * Finding the bookings is separate from cancelling them, so the screen can show what a block would cancel before it is saved.
 * A cancellation is a decision made here like any other: it goes through BookingStatusRecorder (timeline, version, sent back to the
 * mobile app) and is audited.
 */
trait CancelsBookingsOnRestrictionDates
{
    /**
     * The ids of the bookings of the type that sit on one of the Y-m-d dates and are not closed yet - the ones a block on these dates cancels.
     * @param int $bookingTypeId, array<int, string> $dates @return array<int, int>
     */
    protected function findBookingIdsOnRestrictedDates(int $bookingTypeId, array $dates): array
    {
        if (empty($dates)) {
            return [];
        }

        $bookingIds = match ($bookingTypeId) {
            BookingTypeEnum::BARBER_BOOKING->getBookingTypeId() =>
                BarberBooking::query()->whereIn('date', $dates)->where('is_deleted', false)->pluck('booking_id')->all(),
            BookingTypeEnum::CAR_BOOKING->getBookingTypeId() =>
                $this->getBookingIdsWithDateValuesInRange($bookingTypeId, [
                    FormFieldEnum::CAR_BOOKING_START_DATE->getFormFieldId(),
                    FormFieldEnum::CAR_BOOKING_END_DATE->getFormFieldId(),
                ], $dates),
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId() =>
                $this->getBookingIdsWithDateValuesInRange($bookingTypeId, [FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId()], $dates),
            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId() =>
                $this->getBookingIdsWithDateValuesInRange($bookingTypeId, [FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId()], $dates),
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId() =>
                $this->getBookingIdsWithDateValuesInRange($bookingTypeId, [
                    FormFieldEnum::TRANSPORTATION_START_DATE->getFormFieldId(),
                    FormFieldEnum::TRANSPORTATION_END_DATE->getFormFieldId(),
                ], $dates),
            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId() =>
                $this->getBookingIdsWithDateValuesInRange($bookingTypeId, [FormFieldEnum::CAR_MAINTANENCE_DATE_SET->getFormFieldId()], $dates),
            default => [],
        };

        if ($bookingIds === []) {
            return [];
        }

        // A booking that is closed already is left as it is.
        return Booking::query()
            ->whereIn('id', array_values(array_unique($bookingIds)))
            ->where('booking_type_id', $bookingTypeId)
            ->whereNotIn('status_id', StatusBookingEnum::terminalIds())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** Cancel the booking type's bookings on the newly restricted Y-m-d dates. @param int $bookingTypeId, array<int, string> $dates */
    protected function cancelBookingsOnNewlyRestrictedDates(int $bookingTypeId, array $dates): void
    {
        $bookingIds = $this->findBookingIdsOnRestrictedDates($bookingTypeId, $dates);

        if ($bookingIds === []) {
            return;
        }

        $actor = Auth::user() ?? throw new \LogicException('Bookings are canceled on behalf of a logged in person.');
        $recorder = app(BookingStatusRecorder::class);
        $audit = app(AuditService::class);
        $cancelStatus = BookingTypeEnum::getCancelStatusForRestrictionDates($bookingTypeId);

        foreach (Booking::query()->whereIn('id', $bookingIds)->lockForUpdate()->get() as $booking) {
            $before = ['status_id' => (int) $booking->status_id, 'version' => (int) $booking->version];
            $correlationId = $recorder->apply($booking, $cancelStatus, $actor, 'system', (string) Str::uuid());

            $audit->record([
                'action' => 'booking.canceled_by_restriction',
                'entity_type' => 'booking',
                'entity_id' => $booking->serial_number,
                'booking_serial' => $booking->serial_number,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
                'source' => 'automation',
                'before' => $before,
                'after' => ['status_id' => $cancelStatus->value, 'version' => (int) $booking->version],
                'reason' => 'התאריך נחסם להזמנות: ' . implode(', ', $dates),
                'correlation_id' => $correlationId,
            ], $actor);
        }
    }

    /** Find bookings whose date fields match one of the given restricted dates. */
    private function getBookingIdsWithDateValuesInRange(
        int $bookingTypeId,
        array $dateFormFieldIds,
        array $restrictedDates
    ): array {
        $bookingIds = [];

        $bookingValues = BookingValue::with(['booking' => function ($q) use ($bookingTypeId) {
            $q->where('booking_type_id', $bookingTypeId);
        }])
            ->whereIn('form_field_id', $dateFormFieldIds)
            ->get();

        foreach ($bookingValues as $bookingValue) {
            if (!$bookingValue->booking) {
                continue;
            }

            // Extract the date from the value (could be array or string)
            $dateValue = $this->extractDateFromValue($bookingValue->value);

            if ($dateValue && in_array($dateValue, $restrictedDates)) {
                $bookingIds[] = $bookingValue->booking_id;
            }
        }

        return array_unique($bookingIds);
    }

    /** Extract date string from a BookingValue's value field (array, JSON string, or plain string). */
    private function extractDateFromValue($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        // If it's already an array (cast by model)
        if (is_array($value) && count($value) > 0) {
            // For date ranges, the first element is the start date
            $firstElement = $value[0] ?? null;
            return $this->formatDateString($firstElement);
        }

        // If it's a string (JSON string or plain string)
        if (is_string($value)) {
            // Try to parse as JSON array first
            $decoded = json_decode($value, true);
            if (is_array($decoded) && count($decoded) > 0) {
                return $this->formatDateString($decoded[0] ?? null);
            }
            // If not an array, treat as plain date string
            return $this->formatDateString($value);
        }

        return null;
    }

    /** Format and validate a date string to Y-m-d format. */
    private function formatDateString($date): ?string
    {
        if (is_null($date) || (is_string($date) && trim($date) === '')) {
            return null;
        }

        try {
            // Attempt to parse and format the date
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning(
                'CancelsBookingsOnRestrictionDates: could not parse date value',
                ['value' => $date, 'error' => $e->getMessage()]
            );
            return null;
        }
    }
}

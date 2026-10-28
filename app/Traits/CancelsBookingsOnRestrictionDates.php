<?php

namespace App\Traits;

use App\Enums\BookingTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\StatusBookingEnum;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingValue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/** Cancel bookings on dates newly added to a booking type's restriction list (the type's cancel status; the web system sends no SMS). */
trait CancelsBookingsOnRestrictionDates
{
    /** Cancel the booking type's bookings on the newly restricted Y-m-d dates. @param int $bookingTypeId, array<int, string> $dates */
    protected function cancelBookingsOnNewlyRestrictedDates(int $bookingTypeId, array $dates): void
    {
        if (empty($dates)) {
            return;
        }

        match ($bookingTypeId) {
            BookingTypeEnum::BARBER_BOOKING->getBookingTypeId() =>
                $this->cancelBarberBookingsOnDates($dates),
            BookingTypeEnum::CAR_BOOKING->getBookingTypeId() =>
                $this->cancelCarBookingsOnDates($dates),
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId() =>
                $this->cancelFoodBookingsOnDates(
                    $bookingTypeId,
                    $dates,
                    FormFieldEnum::IN_UNIT_SUPPLY_DATE->getFormFieldId()
                ),
            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId() =>
                $this->cancelFoodBookingsOnDates(
                    $bookingTypeId,
                    $dates,
                    FormFieldEnum::OUT_UNIT_SUPPLY_DATE->getFormFieldId()
                ),
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId() =>
                $this->cancelTransportationBookingsOnDates($dates),
            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId() =>
                $this->cancelCarMaintenanceBookingsOnDates($dates),
            default => null,
        };
    }

    /** @param array<int, string> $dates */
    private function cancelBarberBookingsOnDates(array $dates): void
    {
        $activeCancelStatusIds = collect([
            StatusBookingEnum::RECEIVED,
            StatusBookingEnum::PROCESSING,
            StatusBookingEnum::VICE_APPROVER_APPROVED,
            StatusBookingEnum::VICE_APPROVER_REJECTED,
            StatusBookingEnum::APPROVED,
            StatusBookingEnum::APPROVED_BY_BARBER,
        ])
            ->map(fn($status) => $status->value)
            ->toArray();

        $barberBookings = BarberBooking::with(['user', 'booking.bookingType'])
            ->whereIn('date', $dates)
            ->where('is_deleted', false)
            ->whereHas('booking', fn($q) => $q->whereIn('status_id', $activeCancelStatusIds))
            ->get();

        foreach ($barberBookings as $barberBooking) {
            $this->cancelBarberBookingForChangingTime($barberBooking);
        }
    }

    private function cancelBarberBookingForChangingTime(BarberBooking $barberBooking): void
    {
        if (!$barberBooking->booking || !$barberBooking->user) {
            return;
        }

        $cancelStatus = BookingTypeEnum::getCancelStatusForRestrictionDates(
            (int) $barberBooking->booking->booking_type_id
        );

        $barberBooking->booking->update([
            'status_id' => $cancelStatus->value,
            'updated_at' => now(),
        ]);

        $barberBooking->update(['is_deleted' => true]);
    }

    /** Cancel CAR_BOOKING bookings whose date range covers a newly restricted date. */
    private function cancelCarBookingsOnDates(array $dates): void
    {
        $startDateFieldId = FormFieldEnum::CAR_BOOKING_START_DATE->getFormFieldId();
        $endDateFieldId = FormFieldEnum::CAR_BOOKING_END_DATE->getFormFieldId();

        $bookingIds = $this->getBookingIdsWithDateValuesInRange(
            BookingTypeEnum::CAR_BOOKING->getBookingTypeId(),
            [$startDateFieldId, $endDateFieldId],
            $dates
        );

        $this->cancelBookingsByIds($bookingIds);
    }

    /** Cancel FOOD_BOOKING (IN_UNIT or OUT_UNIT) bookings on newly restricted dates. */
    private function cancelFoodBookingsOnDates(
        int $bookingTypeId,
        array $dates,
        int $dateFormFieldId
    ): void {
        $bookingIds = $this->getBookingIdsWithDateValuesInRange(
            $bookingTypeId,
            [$dateFormFieldId],
            $dates
        );

        $this->cancelBookingsByIds($bookingIds);
    }

    /** Cancel TRANSPORTATION_BOOKING bookings whose date range covers a newly restricted date. */
    private function cancelTransportationBookingsOnDates(array $dates): void
    {
        $startDateFieldId = FormFieldEnum::TRANSPORTATION_START_DATE->getFormFieldId();
        $endDateFieldId = FormFieldEnum::TRANSPORTATION_END_DATE->getFormFieldId();

        $bookingIds = $this->getBookingIdsWithDateValuesInRange(
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId(),
            [$startDateFieldId, $endDateFieldId],
            $dates
        );

        $this->cancelBookingsByIds($bookingIds);
    }

    /** Cancel CAR_MAINTENANCE_BOOKING bookings on newly restricted dates. */
    private function cancelCarMaintenanceBookingsOnDates(array $dates): void
    {
        $dateSetFieldId = FormFieldEnum::CAR_MAINTANENCE_DATE_SET->getFormFieldId();

        $bookingIds = $this->getBookingIdsWithDateValuesInRange(
            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId(),
            [$dateSetFieldId],
            $dates
        );

        $this->cancelBookingsByIds($bookingIds);
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

    /** Cancel bookings by ID; skips bookings in a terminal status. */
    private function cancelBookingsByIds(array $bookingIds): void
    {
        if (empty($bookingIds)) {
            return;
        }

        $bookings = Booking::with(['user', 'bookingType'])
            ->whereIn('id', $bookingIds)
            ->get();

        foreach ($bookings as $booking) {
            if (!$booking->user) {
                continue;
            }

            $status = StatusBookingEnum::tryFrom($booking->status_id);
            if (!$status || !$status->canBeCanceledByRestrictionDates()) {
                continue;
            }

            $cancelStatus = BookingTypeEnum::getCancelStatusForRestrictionDates(
                (int) $booking->booking_type_id
            );

            $booking->update([
                'status_id' => $cancelStatus->value,
                'updated_at' => now(),
            ]);
        }
    }
}

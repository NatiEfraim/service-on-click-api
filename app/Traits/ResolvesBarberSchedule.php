<?php

namespace App\Traits;

use App\Enums\BookingTypeEnum;
use App\Enums\SystemReferenceEnum;
use Carbon\Carbon;

/** Resolve the barber service window from the barber system references, so it follows the admin config. */
trait ResolvesBarberSchedule
{
    /** Open service dates from today up to the configured advance window, excluding Friday/Saturday. */
    protected function barberServiceDates(): array
    {
        $dates = [];
        $cursor = Carbon::today();
        $lastDate = $cursor->copy()->addMonths($this->barberAdvanceMonths());

        while ($cursor->lte($lastDate)) {
            if (!$cursor->isFriday() && !$cursor->isSaturday()) {
                $dates[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return $dates;
    }

    /** @return array{start_time?: string, end_time?: string} */
    protected function barberServiceHours(): array
    {
        return [
            'start_time' => $this->barberReferenceValue(SystemReferenceEnum::BARBER_SERVICE_HOURS)['start_time']
                ?? config('app.booking_type.barber_booking.service_start_time'),

            'end_time' => $this->barberReferenceValue(SystemReferenceEnum::BARBER_SERVICE_HOURS)['end_time']
                ?? config('app.booking_type.barber_booking.service_end_time'),
        ];
    }

    /** @return array{start_time?: string, end_time?: string} */
    protected function barberBreakHours(): array
    {
        return [
            'start_time' => $this->barberReferenceValue(SystemReferenceEnum::BARBER_BREAK_HOURS)['start_time']
                ?? config('app.booking_type.barber_booking.break_start_time'),

            'end_time' => $this->barberReferenceValue(SystemReferenceEnum::BARBER_BREAK_HOURS)['end_time']
                ?? config('app.booking_type.barber_booking.break_end_time'),
        ];
    }

    protected function barberHaircutTime(): int
    {
        return (int) (
            $this->barberReferenceValue(SystemReferenceEnum::BARBER_HAIRCUT_TIME)['haircut_time']
            ?? config('app.booking_type.barber_booking.haircut_time', 15)
        );
    }

    protected function barberAdvanceMonths(): int
    {
        return (int) (
            $this->barberReferenceValue(SystemReferenceEnum::BARBER_ADVANCE_MONTHS)['advance_month']
            ?? config('app.booking_type.barber_booking.advance_month', 2)
        );
    }

    protected function barberReferenceValue(SystemReferenceEnum $reference): array
    {
        return SystemReferenceEnum::getSystemReferenceValue(
            bookingTypeId: BookingTypeEnum::BARBER_BOOKING->getBookingTypeId(),
            systemReferenceId: $reference->getSystemReferenceId(),
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\BarberBooking;
use App\Models\Booking;
use App\Services\ImportBookingService;
use App\Traits\ResolvesBarberSchedule;
use Carbon\Carbon;

/**
 * Correcting the date or time of a barber booking moves its slot in the barber's schedule - and only to a time the barber is free.
 * (Form fields 83 and 84 are the date and the time of a barber booking.)
 */
class BarberValueEditTest extends ImportJsonTestCase
{
    private const DATE_FIELD = '83';
    private const TIME_FIELD = '84';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** The barber's service hours, break and slot length, as the application reads them. @return array{start: string, end: string, break_start: string, break_end: string, slot: int} */
    private function schedule(): array
    {
        $reader = new class {
            use ResolvesBarberSchedule;

            public function read(): array
            {
                return [
                    'start' => Carbon::parse($this->barberServiceHours()['start_time'])->format('H:i'),
                    'end' => Carbon::parse($this->barberServiceHours()['end_time'])->format('H:i'),
                    'break_start' => Carbon::parse($this->barberBreakHours()['start_time'])->format('H:i'),
                    'break_end' => Carbon::parse($this->barberBreakHours()['end_time'])->format('H:i'),
                    'slot' => $this->barberHaircutTime() + $this->barberCleanTime(),
                ];
            }
        };

        return $reader->read();
    }

    /** A service day (Sunday to Thursday) some days from today. */
    private function serviceDay(int $daysFromToday): string
    {
        $day = now()->addDays($daysFromToday);

        while ($day->isFriday() || $day->isSaturday()) {
            $day = $day->addDay();
        }

        return $day->toDateString();
    }

    /** Import barber bookings (all of them in status 1: the vice approver's turn). @param array<int, array{0: int, 1: string, 2: string}> $slots serial, date, time */
    private function importBarberBookings(array $slots): void
    {
        $this->putBookingFile(array_map(fn (array $slot): array => $this->barberBooking($slot[0], $slot[1], $slot[2]), $slots));
        app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    private function correct(int $serial, array $values, int $version = 1)
    {
        return $this->as(self::VICE_APPROVER)->putJson("/api/bookings-approver-management/bookings/{$serial}/values", ['values' => $values, 'version' => $version]);
    }

    private function slotOf(int $serial): BarberBooking
    {
        return BarberBooking::where('booking_id', Booking::where('serial_number', $serial)->value('id'))->firstOrFail();
    }

    public function test_a_corrected_time_moves_the_slot_and_the_values(): void
    {
        $schedule = $this->schedule();
        $day = $this->serviceDay(3);
        $this->importBarberBookings([[6001, $day, $schedule['start']]]);

        // Half an hour after opening is free (nobody else has a slot) and ends before the break.
        $later = Carbon::createFromFormat('H:i', $schedule['start'])->addMinutes(30)->format('H:i');
        $this->assertLessThanOrEqual($schedule['break_start'], Carbon::createFromFormat('H:i', $later)->addMinutes($schedule['slot'])->format('H:i'), 'the schedule leaves room before the break');

        $this->correct(6001, [self::TIME_FIELD => $later])->assertOk();

        $slot = $this->slotOf(6001);
        $this->assertSame($later, substr($slot->start_time, 0, 5));
        $this->assertSame(Carbon::createFromFormat('H:i', $later)->addMinutes($schedule['slot'])->format('H:i'), substr($slot->end_time, 0, 5));
        $this->assertSame($day, Carbon::parse($slot->date)->toDateString(), 'the date did not change');
    }

    public function test_a_corrected_date_moves_the_slot_to_that_day(): void
    {
        $schedule = $this->schedule();
        $day = $this->serviceDay(3);
        $otherDay = $this->serviceDay(7);
        $this->importBarberBookings([[6001, $day, $schedule['start']]]);

        $this->correct(6001, [self::DATE_FIELD => $otherDay])->assertOk();

        $this->assertSame($otherDay, Carbon::parse($this->slotOf(6001)->date)->toDateString());
    }

    public function test_a_time_in_the_break_or_outside_the_service_hours_is_refused_and_nothing_moves(): void
    {
        $schedule = $this->schedule();
        $day = $this->serviceDay(3);
        $this->importBarberBookings([[6001, $day, $schedule['start']]]);

        $this->correct(6001, [self::TIME_FIELD => $schedule['break_start']])
            ->assertUnprocessable()->assertJsonPath('message', 'השעה שנבחרה חופפת להפסקת המספרה');

        $this->correct(6001, [self::TIME_FIELD => $schedule['end']])
            ->assertUnprocessable()->assertJsonPath('message', 'השעה שנבחרה מחוץ לשעות הפעילות של המספרה');

        // (An untouched slot keeps the "HH:MM" the import wrote; a moved one is "HH:MM:SS".)
        $this->assertSame($schedule['start'], substr($this->slotOf(6001)->start_time, 0, 5), 'the slot stayed');
        $this->assertSame(1, Booking::where('serial_number', 6001)->value('version'), 'and so did the booking');
    }

    public function test_a_time_another_booking_holds_is_refused(): void
    {
        $schedule = $this->schedule();
        $day = $this->serviceDay(3);
        $second = Carbon::createFromFormat('H:i', $schedule['start'])->addMinutes(30)->format('H:i');
        $this->importBarberBookings([[6001, $day, $schedule['start']], [6002, $day, $second]]);

        // 6002 asks for the very time 6001 has.
        $this->correct(6002, [self::TIME_FIELD => $schedule['start']])
            ->assertUnprocessable()->assertJsonPath('message', 'השעה שנבחרה תפוסה');

        $this->assertSame($second, substr($this->slotOf(6002)->start_time, 0, 5));
    }

    public function test_a_time_that_runs_past_midnight_is_outside_the_service_hours(): void
    {
        $schedule = $this->schedule();
        $this->importBarberBookings([[6001, $this->serviceDay(3), $schedule['start']]]);

        // 23:50 plus the length of a haircut is "00:35" the next day - which must not look earlier than the closing time.
        $this->correct(6001, [self::TIME_FIELD => '23:50'])
            ->assertUnprocessable()->assertJsonPath('message', 'השעה שנבחרה מחוץ לשעות הפעילות של המספרה');
    }

    public function test_a_closed_booking_does_not_hold_its_time(): void
    {
        $schedule = $this->schedule();
        $day = $this->serviceDay(3);
        $second = Carbon::createFromFormat('H:i', $schedule['start'])->addMinutes(30)->format('H:i');
        $this->importBarberBookings([[6001, $day, $schedule['start']], [6002, $day, $second]]);

        // 6001 is rejected: its slot row is still there (only the statuses the mobile app frees mark it deleted) but it holds nothing.
        Booking::where('serial_number', 6001)->update(['status_id' => 4]);

        $this->correct(6002, [self::TIME_FIELD => $schedule['start']])->assertOk();
        $this->assertSame($schedule['start'], substr($this->slotOf(6002)->start_time, 0, 5));
    }

    public function test_a_day_the_barber_does_not_work_is_refused(): void
    {
        $schedule = $this->schedule();
        $this->importBarberBookings([[6001, $this->serviceDay(3), $schedule['start']]]);

        $friday = now()->next(Carbon::FRIDAY)->toDateString();

        $this->correct(6001, [self::DATE_FIELD => $friday])
            ->assertUnprocessable()->assertJsonPath('message', 'התאריך שנבחר אינו יום פעילות של המספרה');
    }
}

<?php

namespace Tests\Feature;

use App\Models\ApplicationLog;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\Comment;
use App\Models\User;

/**
 * The barber route: one step - the barber approves (11) or rejects (12, with a comment) a received booking. Who may, what stays behind (the slot,
 * the handler, the comment) and what the barber's dashboard lists.
 */
class BarberBookingRouteTest extends ManagementTestCase
{
    private const SERIAL = 800000005;
    private const BARBER_TYPE = 5;

    private function statusUrl(int $serial): string
    {
        return '/api/bookings-approver-management/bookings/' . $serial . '/status';
    }

    private function bookingUrl(int $serial): string
    {
        return '/api/bookings-approver-management/bookings/' . $serial;
    }

    /** A booking (a barber booking by default, with its slot taken) in the given status. */
    private function barberBooking(int $statusId = 1, int $serial = self::SERIAL, int $bookingTypeId = self::BARBER_TYPE): Booking
    {
        $booking = Booking::create([
            'serial_number' => $serial,
            'unit_id' => 1,
            'booking_type_id' => $bookingTypeId,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => $statusId,
            'ext_status_id' => $statusId,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);

        if ($bookingTypeId === self::BARBER_TYPE) {
            BarberBooking::create([
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'date' => '2027-01-03',
                'start_time' => '09:00:00',
                'end_time' => '09:45:00',
                'is_deleted' => false,
            ]);
        }

        return $booking;
    }

    private function slotIsFree(Booking $booking): bool
    {
        return (bool) BarberBooking::where('booking_id', $booking->id)->value('is_deleted');
    }

    /** Send the next request as another person (the test client keeps the guard of the one before). */
    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    public function test_a_received_barber_booking_waits_for_the_barber_who_approves_or_rejects(): void
    {
        $this->barberBooking();

        // The barber (the vice approver of the type) and, in their place, the approver and the admin: the same two buttons.
        foreach ([self::VICE_APPROVER, self::APPROVER, self::ADMIN] as $person) {
            $this->as($person)
                ->getJson($this->bookingUrl(self::SERIAL))
                ->assertOk()
                ->assertJsonPath('data.stage', 'barber')
                ->assertJsonPath('data.stage_label', 'ממתין לספר')
                ->assertJsonCount(2, 'data.actions')
                ->assertJsonPath('data.actions.0.status_id', 11)
                ->assertJsonPath('data.actions.0.label', 'אישור על ידי הספר')
                ->assertJsonPath('data.actions.0.comment_required', false)
                ->assertJsonPath('data.actions.1.status_id', 12)
                ->assertJsonPath('data.actions.1.label', 'דחייה על ידי הספר')
                ->assertJsonPath('data.actions.1.comment_required', true);
        }
    }

    public function test_nothing_of_the_approver_chain_is_accepted_on_a_barber_booking(): void
    {
        $booking = $this->barberBooking();

        // The vice approver's and the approver's statuses, the closing ones, the system's 13 and the requester's 6: none is a move of the barber route.
        foreach ([15, 16, 3, 4, 5, 7, 13, 6] as $status) {
            $this->as(self::ADMIN)
                ->postJson($this->statusUrl(self::SERIAL), ['status_id' => $status, 'version' => 1, 'comment' => 'בדיקה'])
                ->assertForbidden();
        }

        $this->assertSame(1, (int) $booking->fresh()->status_id);
        $this->assertFalse($this->slotIsFree($booking));
    }

    public function test_the_barber_approves_and_the_appointment_keeps_its_slot(): void
    {
        $booking = $this->barberBooking();

        $this->as(self::VICE_APPROVER)
            ->postJson($this->statusUrl(self::SERIAL), ['status_id' => 11, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status_id', 11)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.sync_state', 'pending');

        $booking = $booking->fresh();
        $this->assertSame(11, (int) $booking->status_id);
        $this->assertFalse($booking->is_status_exported, 'the decision is waiting to be sent to the mobile app');
        $this->assertFalse($this->slotIsFree($booking), 'an approved appointment is still ahead: it keeps its slot');
        $this->assertDatabaseHas('vice_approver_has_bookings', ['booking_id' => $booking->id, 'approved_by_vice_approver' => true]);
        $this->assertSame([11], ApplicationLog::where('booking_id', $booking->id)->pluck('status_id')->map(fn ($id): int => (int) $id)->all());

        // Nothing is left for anybody: the route has one step.
        $this->as(self::ADMIN)
            ->getJson($this->bookingUrl(self::SERIAL))
            ->assertOk()
            ->assertJsonPath('data.stage', 'closed')
            ->assertJsonCount(0, 'data.actions');
        $this->as(self::ADMIN)->postJson($this->statusUrl(self::SERIAL), ['status_id' => 12, 'version' => 2, 'comment' => 'בדיקה'])->assertForbidden();
    }

    public function test_the_barber_rejects_with_a_comment_and_the_slot_is_free_again(): void
    {
        $booking = $this->barberBooking();

        // A rejection needs a comment here as everywhere, and a refusal changes nothing.
        $this->as(self::VICE_APPROVER)
            ->postJson($this->statusUrl(self::SERIAL), ['status_id' => 12, 'version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'יש לצרף הערה לביצוע פעולה זו');
        $this->assertSame(1, (int) $booking->fresh()->status_id);
        $this->assertFalse($this->slotIsFree($booking));

        $this->as(self::VICE_APPROVER)
            ->postJson($this->statusUrl(self::SERIAL), ['status_id' => 12, 'version' => 1, 'comment' => 'אין זמינות'])
            ->assertOk()
            ->assertJsonPath('data.status_id', 12);

        $booking = $booking->fresh();
        $this->assertSame(12, (int) $booking->status_id);
        $this->assertTrue($this->slotIsFree($booking), 'rejected: the time can be booked again');
        $this->assertDatabaseHas('vice_approver_has_bookings', ['booking_id' => $booking->id, 'approved_by_vice_approver' => false]);
        $this->assertSame('אין זמינות', Comment::where('booking_id', $booking->id)->value('comment'));
    }

    public function test_the_approver_and_the_admin_may_answer_in_the_barbers_place(): void
    {
        $approved = $this->barberBooking(1, 800000011);
        $rejected = $this->barberBooking(1, 800000012);

        $this->as(self::APPROVER)->postJson($this->statusUrl(800000011), ['status_id' => 11, 'version' => 1])->assertOk();
        $this->as(self::ADMIN)->postJson($this->statusUrl(800000012), ['status_id' => 12, 'version' => 1, 'comment' => 'אין זמינות'])->assertOk();

        $this->assertSame(11, (int) $approved->fresh()->status_id);
        $this->assertSame(12, (int) $rejected->fresh()->status_id);
    }

    public function test_a_barber_booking_that_is_answered_or_closed_has_no_buttons(): void
    {
        foreach ([11 => 800000021, 12 => 800000022, 13 => 800000023, 6 => 800000024] as $status => $serial) {
            $this->barberBooking($status, $serial);

            $this->as(self::ADMIN)
                ->getJson($this->bookingUrl($serial))
                ->assertOk()
                ->assertJsonPath('data.status_id', $status)
                ->assertJsonPath('data.stage', 'closed')
                ->assertJsonCount(0, 'data.actions');
        }
    }

    public function test_a_barber_booking_left_in_a_status_of_the_old_route_waits_for_the_barber_too(): void
    {
        $booking = $this->barberBooking(15);

        $this->as(self::VICE_APPROVER)
            ->getJson($this->bookingUrl(self::SERIAL))
            ->assertOk()
            ->assertJsonPath('data.stage', 'barber')
            ->assertJsonPath('data.actions.0.status_id', 11);

        $this->as(self::VICE_APPROVER)->postJson($this->statusUrl(self::SERIAL), ['status_id' => 11, 'version' => 1])->assertOk();
        $this->assertSame(11, (int) $booking->fresh()->status_id);
    }

    public function test_the_other_booking_types_keep_their_route_and_do_not_get_the_barbers_statuses(): void
    {
        $this->barberBooking(1, 800000031, 1);

        $this->as(self::VICE_APPROVER)
            ->getJson($this->bookingUrl(800000031))
            ->assertOk()
            ->assertJsonPath('data.stage', 'vice_approver')
            ->assertJsonPath('data.actions.0.status_id', 15)
            ->assertJsonPath('data.actions.1.status_id', 16);

        foreach ([11, 12] as $barberStatus) {
            $this->as(self::ADMIN)
                ->postJson($this->statusUrl(800000031), ['status_id' => $barberStatus, 'version' => 1, 'comment' => 'בדיקה'])
                ->assertForbidden();
        }
    }

    public function test_the_barber_dashboard_lists_only_the_barber_statuses(): void
    {
        $dashboard = $this->as(self::ADMIN)
            ->getJson('/api/bookings-approver-management/dashboard/barber-booking')
            ->assertOk()
            ->assertJsonPath('data.auth_user_permission', 'admin_5')
            ->assertJsonPath('data.booking_type.id', self::BARBER_TYPE)
            ->json('data.dashboard');

        $rows = array_values(array_filter($dashboard, 'is_array'));

        $this->assertSame([1, 6, 11, 12, 13], array_column($rows, 'status_id'));
        $this->assertSame(['1', '6', '11', '12', '13'], array_column($rows, 'status_icon'));
        $this->assertSame(array_sum(array_column($rows, 'count')), $dashboard['total_bookings']);

        // Another type keeps its own set.
        $car = $this->as(self::ADMIN)->getJson('/api/bookings-approver-management/dashboard/car-booking')->assertOk()->json('data.dashboard');
        $carStatuses = array_column(array_values(array_filter($car, 'is_array')), 'status_id');
        sort($carStatuses);

        $this->assertSame([1, 3, 4, 5, 6, 7, 15, 16], $carStatuses);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\HttpStatusCodeEnum;
use App\Enums\RoleEnum;
use App\Models\ApplicationLog;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingRestrictionDate;
use App\Models\BookingValue;
use App\Models\Comment;
use App\Models\SyncFile;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Changing a booking's status and correcting its values: the turn-based rules, the version check, the idempotency key,
 * what is left behind (timeline, audit row, the flag that sends the decision back to the mobile app).
 */
class BookingWorkflowTest extends ManagementTestCase
{
    private const SERIAL = 800000001;
    private const STATUS_URL = '/api/bookings-approver-management/bookings/800000001/status';

    private function booking(array $attributes = []): Booking
    {
        return Booking::create($attributes + [
            'serial_number' => self::SERIAL,
            'unit_id' => 1,
            'booking_type_id' => 1,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => 1,
            'ext_status_id' => 1,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);
    }

    /** Send the next request as another person (the test client keeps the guard of the one before). */
    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    public function test_the_vice_approver_passes_a_received_booking_on_and_it_is_queued_for_the_mobile_app(): void
    {
        $this->booking();

        $this->as(self::VICE_APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status_id', 15)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.sync_state', 'pending');

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(15, (int) $booking->status_id);
        $this->assertSame(2, $booking->version);
        $this->assertSame('internal', $booking->status_source);
        $this->assertFalse($booking->is_status_exported, 'the decision is waiting to be sent back');
        $this->assertSame(1, (int) $booking->ext_status_id, 'what the mobile app has is untouched');

        $log = ApplicationLog::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(15, (int) $log->status_id);
        $this->assertSame('internal', $log->source);
        $this->assertFalse($log->is_exported);
        $this->assertSame(User::where('personal_number', self::VICE_APPROVER)->value('id'), $log->user_id);

        $audit = AuditLog::where('action', 'booking.status_change')->firstOrFail();
        $this->assertSame(self::SERIAL, (int) $audit->booking_serial);
        $this->assertSame('success', $audit->result);
        $this->assertSame(['status_id' => 1, 'version' => 1], $audit->before);
        $this->assertSame($log->correlation_id, $audit->correlation_id);

        $this->assertDatabaseHas('vice_approver_has_bookings', ['booking_id' => $booking->id, 'approved_by_vice_approver' => true]);
    }

    public function test_the_vice_approver_cannot_approve_finally_and_the_refusal_is_audited(): void
    {
        $this->booking();

        $this->as(self::VICE_APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', HttpStatusCodeEnum::DENIED_MESSAGE);

        $this->assertSame(1, (int) Booking::where('serial_number', self::SERIAL)->value('status_id'));

        $denied = AuditLog::where('action', 'booking.status_change')->where('result', 'denied')->firstOrFail();
        $this->assertSame('transition 1->3 not allowed', $denied->reason);
    }

    public function test_the_approver_may_skip_the_vice_approver_and_approve_finally(): void
    {
        $booking = $this->booking();

        // The vice approver has not answered, yet the approver is offered the final decision: the approval, and the rejection that needs a comment.
        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonCount(2, 'data.actions')
            ->assertJsonPath('data.actions.0.status_id', 3)
            ->assertJsonPath('data.actions.0.label', 'אישור סופי')
            ->assertJsonPath('data.actions.0.comment_required', false)
            ->assertJsonPath('data.actions.1.status_id', 4)
            ->assertJsonPath('data.actions.1.label', 'דחייה סופית')
            ->assertJsonPath('data.actions.1.comment_required', true);

        // Only the decision is skipped: a booking that is not approved yet cannot be completed or canceled.
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 5, 'version' => 1])->assertForbidden();
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 7, 'version' => 1, 'comment' => 'ביטול'])->assertForbidden();
        $this->assertSame(1, (int) $booking->fresh()->status_id);

        // Straight to the realization stage: one timeline row, nothing of the vice approver in between.
        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status_id', 3)
            ->assertJsonPath('data.sync_state', 'pending');

        $booking = $booking->fresh();
        $this->assertSame(3, (int) $booking->status_id);
        $this->assertFalse($booking->is_status_exported, 'the decision is waiting to be sent back');
        $this->assertSame([3], ApplicationLog::where('booking_id', $booking->id)->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertDatabaseMissing('vice_approver_has_bookings', ['booking_id' => $booking->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'booking.status_change', 'result' => 'success', 'booking_serial' => self::SERIAL]);

        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonPath('data.stage', 'realization')
            ->assertJsonPath('data.actions.0.status_id', 5);
    }

    public function test_the_approver_may_skip_the_vice_approver_and_reject_finally(): void
    {
        $booking = $this->booking();

        // A rejection needs a comment here as everywhere, and a refusal changes nothing.
        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 4, 'version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'יש לצרף הערה לביצוע פעולה זו');
        $this->assertSame(1, (int) $booking->fresh()->status_id);

        // Straight to rejected: one timeline row, nothing of the vice approver in between, and the reason is kept as a comment.
        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 4, 'version' => 1, 'comment' => 'חסר מידע'])
            ->assertOk()
            ->assertJsonPath('data.status_id', 4)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.sync_state', 'pending');

        $booking = $booking->fresh();
        $this->assertSame(4, (int) $booking->status_id);
        $this->assertSame('internal', $booking->status_source);
        $this->assertFalse($booking->is_status_exported, 'the decision is waiting to be sent back');
        $this->assertSame([4], ApplicationLog::where('booking_id', $booking->id)->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('חסר מידע', Comment::where('booking_id', $booking->id)->value('comment'));
        $this->assertDatabaseMissing('vice_approver_has_bookings', ['booking_id' => $booking->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'booking.status_change', 'result' => 'success', 'booking_serial' => self::SERIAL, 'reason' => 'חסר מידע']);

        // Closed: no buttons any more, and nobody changes it.
        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonPath('data.stage', 'closed')
            ->assertJsonCount(0, 'data.actions');
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 2])->assertForbidden();
    }

    public function test_the_approver_does_not_correct_the_values_of_a_stage_they_skip(): void
    {
        $this->bookingWithDateAndTime();
        Booking::where('serial_number', self::SERIAL)->update(['status_id' => 1]);

        // The shortcut is there, the vice approver's turn to correct a date or a time is not.
        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonPath('data.actions.0.status_id', 3);

        $this->assertFalse($this->mayEditValues(self::APPROVER, self::SERIAL));
    }

    public function test_the_admin_takes_every_turn_and_may_skip_ahead(): void
    {
        $booking = $this->booking();
        $admin = User::where('personal_number', self::ADMIN)->firstOrFail();

        // Received is the vice approver's turn: the admin sees the same buttons, then the final decision that skips it.
        $this->as(self::ADMIN)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonCount(4, 'data.actions')
            ->assertJsonPath('data.actions.0.status_id', 15)
            ->assertJsonPath('data.actions.1.status_id', 16)
            ->assertJsonPath('data.actions.2.status_id', 3)
            ->assertJsonPath('data.actions.3.status_id', 4);

        // Skipping is only the final decision: never the requester's own cancellation, and no completing a booking that is not approved.
        $this->as(self::ADMIN)->postJson(self::STATUS_URL, ['status_id' => 6, 'version' => 1])->assertForbidden();
        $this->as(self::ADMIN)->postJson(self::STATUS_URL, ['status_id' => 5, 'version' => 1])->assertForbidden();
        $this->assertSame(1, (int) $booking->fresh()->status_id);

        // The whole route, one turn after the other: vice approver, approver, approver again.
        $this->as(self::ADMIN)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertOk()->assertJsonPath('data.status_id', 15);
        $this->as(self::ADMIN)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 2])->assertOk()->assertJsonPath('data.status_id', 3);
        $this->as(self::ADMIN)->postJson(self::STATUS_URL, ['status_id' => 5, 'version' => 3])->assertOk()->assertJsonPath('data.status_id', 5);

        // Closed: nothing more to do, not even for the admin.
        $this->as(self::ADMIN)->postJson(self::STATUS_URL, ['status_id' => 7, 'version' => 4, 'comment' => 'ביטול'])->assertForbidden();

        $this->assertSame([15, 3, 5], ApplicationLog::where('booking_id', $booking->id)->orderBy('id')->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$admin->id], ApplicationLog::where('booking_id', $booking->id)->pluck('user_id')->unique()->values()->all(), 'the timeline names the admin');
        $this->assertSame(3, AuditLog::where('action', 'booking.status_change')->where('result', 'success')->where('actor_id', self::ADMIN)->count());
        $this->assertDatabaseHas('vice_approver_has_bookings', ['booking_id' => $booking->id, 'user_id' => $admin->id]);
    }

    public function test_the_admin_and_the_super_admin_may_reject_finally_from_every_stage_below_the_approver(): void
    {
        // The vice approver's stage on a car, what the control officer passed on and the control officer's own stage on a transportation.
        $stages = [[1, 1], [8, 17], [8, 1], [8, 18], [8, 14]];

        foreach ([[self::ADMIN, 800000100], [self::SUPER_ADMIN, 800000200]] as [$person, $firstSerial]) {
            foreach ($stages as $index => [$bookingTypeId, $statusId]) {
                $serial = $firstSerial + $index;
                $booking = $this->booking(['serial_number' => $serial, 'booking_type_id' => $bookingTypeId, 'status_id' => $statusId]);
                $url = "/api/bookings-approver-management/bookings/{$serial}/status";

                $this->as($person)->postJson($url, ['status_id' => 4, 'version' => 1])->assertUnprocessable();
                $this->as($person)->postJson($url, ['status_id' => 4, 'version' => 1, 'comment' => 'חסר מידע'])->assertOk()->assertJsonPath('data.status_id', 4);

                $this->assertSame(4, (int) $booking->fresh()->status_id, "{$person}: type {$bookingTypeId} from status {$statusId}");
                $this->assertSame([4], ApplicationLog::where('booking_id', $booking->id)->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
            }
        }
    }

    public function test_an_admin_acts_only_on_the_types_and_the_unit_they_administer(): void
    {
        $this->booking();

        $otherUnit = User::factory()->create(['personal_number' => '1000098', 'unit_id' => 2]);
        $otherUnit->assignRole('admin');
        $otherUnit->givePermissionTo('admin_1');

        $otherType = User::factory()->create(['personal_number' => '1000097', 'unit_id' => 1]);
        $otherType->assignRole('admin');
        $otherType->givePermissionTo('admin_2');

        foreach (['1000098', '1000097'] as $stranger) {
            $this->as($stranger)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertNotFound();
        }

        $this->assertSame(1, (int) Booking::where('serial_number', self::SERIAL)->value('status_id'));
    }

    public function test_the_super_admin_acts_on_every_booking_of_every_unit(): void
    {
        $this->booking(['unit_id' => 2]);
        $url = '/api/bookings-approver-management/bookings?ids=' . self::SERIAL . '&mine=1';

        // The super admin holds no per-type permission, yet the buttons are there and the "mine" tab lists the booking.
        $this->as(self::SUPER_ADMIN)->getJson($url)->assertOk()->assertJsonPath('data.0.actions.0.status_id', 15);
        $this->as(self::SUPER_ADMIN)->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)->assertOk();
        $this->assertTrue($this->mayEditValues(self::SUPER_ADMIN, self::SERIAL));

        // As the admin of the type, the super admin may also skip the vice approver with the final approval.
        $this->as(self::SUPER_ADMIN)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])->assertOk();

        $this->assertSame(3, (int) Booking::where('serial_number', self::SERIAL)->value('status_id'));
    }

    public function test_the_awaiting_me_queue_holds_the_bookings_still_with_the_stages_below_the_approver(): void
    {
        $this->booking(['serial_number' => 800000001, 'status_id' => 1]);                            // the vice approver's stage
        $this->booking(['serial_number' => 800000002, 'status_id' => 17, 'booking_type_id' => 8]);   // transport: the control officer passed it on
        $this->booking(['serial_number' => 800000003, 'status_id' => 1, 'booking_type_id' => 8]);    // transport: the control officer's stage
        $this->booking(['serial_number' => 800000004, 'status_id' => 15]);                           // the approver's own stage
        $this->booking(['serial_number' => 800000005, 'status_id' => 5]);                            // closed
        $url = '/api/bookings-approver-management/bookings?mine=1&sort=serial_number&dir=asc&ids=800000001,800000002,800000003,800000004,800000005';

        // Everything open is on the approver's list, each with the final decision: the approval and the rejection.
        $rows = $this->as(self::APPROVER)->getJson($url)->assertOk()->json('data');
        $this->assertSame([800000001, 800000002, 800000003, 800000004], array_column($rows, 'serial_number'));
        $this->assertSame([[3, 4], [3, 4], [3, 4], [3, 4]], array_map(fn (array $row): array => array_column($row['actions'], 'status_id'), $rows));
        $this->assertSame(['vice_approver', 'vice_approver', 'control_officer', 'approver'], array_column($rows, 'stage'));

        foreach ([self::ADMIN, self::SUPER_ADMIN] as $person) {
            $this->assertSame([800000001, 800000002, 800000003, 800000004], $this->as($person)->getJson($url)->assertOk()->json('data.*.serial_number'), $person);
        }

        // The handlers keep below the approver's stage: the vice approver has their own two and the transportation booking still waiting for the
        // control officer, which they may take over - with the same buttons - but not the one waiting for the approver.
        $rows = $this->as(self::VICE_APPROVER)->getJson($url)->assertOk()->json('data');
        $this->assertSame([800000001, 800000002, 800000003], array_column($rows, 'serial_number'));
        $this->assertSame([[15, 16], [15, 16, 8, 9, 10], [15, 16, 8, 9, 10]], array_map(fn (array $row): array => array_column($row['actions'], 'status_id'), $rows));
    }

    /** A person who is only the control officer of the transportation type, as the seeded system has them: the role and that type's permission. */
    private function controlOfficer(): string
    {
        $officer = User::factory()->create(['personal_number' => '1000008', 'unit_id' => 1]);
        $officer->assignRole(RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value);
        $officer->givePermissionTo('transportation_control_officer_8');

        return '1000008';
    }

    public function test_a_transportation_booking_goes_from_the_control_officer_to_the_vice_approver_to_the_approver(): void
    {
        $officer = $this->controlOfficer();
        $booking = $this->booking(['booking_type_id' => 8]);
        $url = '/api/bookings-approver-management/bookings/' . self::SERIAL;

        // Received: the control officer's turn. The vice approver sees it (they may take it over, see the next tests) but the control officer's
        // own answer is not theirs.
        $this->as(self::VICE_APPROVER)->getJson($url)->assertOk()->assertJsonPath('data.stage', 'control_officer');
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 17, 'version' => 1])->assertForbidden();

        $this->as($officer)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.stage', 'control_officer')
            ->assertJsonPath('data.actions.0.status_id', 17)
            ->assertJsonPath('data.actions.1.status_id', 18);
        $this->as($officer)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertForbidden();
        $this->as($officer)->postJson(self::STATUS_URL, ['status_id' => 17, 'version' => 1])->assertOk()->assertJsonPath('data.status_id', 17);

        // Passed on: the vice approver's turn - the control officer is done with it.
        $this->as($officer)->getJson($url)->assertNotFound();
        $this->as(self::VICE_APPROVER)->getJson($url)->assertOk()->assertJsonPath('data.stage', 'vice_approver');
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 2])->assertOk();

        // And last the approver.
        $this->as(self::APPROVER)->getJson($url)->assertOk()->assertJsonPath('data.stage', 'approver')->assertJsonPath('data.actions.0.status_id', 3);
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 3])->assertOk();

        $this->assertSame([17, 15, 3], ApplicationLog::where('booking_id', $booking->id)->orderBy('id')->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_the_vice_approver_takes_a_transportation_booking_over_from_the_control_officer(): void
    {
        $booking = $this->booking(['booking_type_id' => 8]);
        $url = '/api/bookings-approver-management/bookings/' . self::SERIAL;
        $vice = User::where('personal_number', self::VICE_APPROVER)->firstOrFail();

        // Waiting at the control officer and nobody has answered: the booking is on the vice approver's own list, with their buttons and the holds.
        $row = $this->as(self::VICE_APPROVER)->getJson('/api/bookings-approver-management/bookings?mine=1&ids=' . self::SERIAL)->assertOk()->json('data.0');
        $this->assertSame('control_officer', $row['stage']);
        $this->assertSame([15, 16, 8, 9, 10], array_column($row['actions'], 'status_id'));
        $this->assertSame([false, true, false, false, false], array_column($row['actions'], 'comment_required'));

        // The values stay with the control officer, whose turn it is.
        $this->as(self::VICE_APPROVER)->getJson($url)->assertOk()->assertJsonPath('data.stage', 'control_officer');
        $this->assertFalse($this->mayEditValues(self::VICE_APPROVER, self::SERIAL));

        // The control officer's own answer and the final decision are not theirs, and passing it on needs no comment but a rejection does.
        foreach ([17, 18, 3, 4] as $notTheirs) {
            $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => $notTheirs, 'version' => 1, 'comment' => 'חסר מידע'])->assertForbidden();
        }
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 16, 'version' => 1])->assertUnprocessable();
        $this->assertSame(1, (int) $booking->fresh()->status_id);

        // A vice approver of another booking type has no say here.
        $other = User::factory()->create(['personal_number' => '1000096', 'unit_id' => 1]);
        $other->assignRole(RoleEnum::VICE_APPROVER->value);
        $other->givePermissionTo('vice_approver_1');
        $this->as('1000096')->getJson($url)->assertNotFound();

        // They pass it on to the approver: one timeline row, no answer of the control officer in between, and they are tied to the booking.
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertOk()->assertJsonPath('data.status_id', 15);

        $this->assertSame([15], ApplicationLog::where('booking_id', $booking->id)->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertDatabaseHas('vice_approver_has_bookings', ['booking_id' => $booking->id, 'user_id' => $vice->id, 'approved_by_vice_approver' => true]);

        // Now it is the approver's turn, and the vice approver no longer sees it.
        $this->as(self::VICE_APPROVER)->getJson($url)->assertNotFound();
        $this->as(self::APPROVER)->getJson($url)->assertOk()->assertJsonPath('data.stage', 'approver')->assertJsonPath('data.actions.0.status_id', 3);
    }

    public function test_the_vice_approver_may_recommend_rejecting_a_transportation_booking_waiting_at_the_control_officer(): void
    {
        $booking = $this->booking(['booking_type_id' => 8]);

        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 16, 'version' => 1, 'comment' => 'חסר מידע'])->assertOk()->assertJsonPath('data.status_id', 16);

        $this->assertSame(16, (int) $booking->fresh()->status_id);
        $this->assertDatabaseHas('vice_approver_has_bookings', ['booking_id' => $booking->id, 'approved_by_vice_approver' => false]);

        // The final word stays with the approver.
        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonPath('data.stage', 'approver')
            ->assertJsonPath('data.actions.1.status_id', 4);
    }

    public function test_the_approver_skips_the_control_officer_and_the_vice_approver_of_a_transportation_booking(): void
    {
        $booking = $this->booking(['booking_type_id' => 8]);

        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonPath('data.stage', 'control_officer')
            ->assertJsonCount(2, 'data.actions')
            ->assertJsonPath('data.actions.0.status_id', 3)
            ->assertJsonPath('data.actions.1.status_id', 4);

        // Not the supplier holds: from the stages below, the approver decides.
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 8, 'version' => 1])->assertForbidden();

        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])->assertOk()->assertJsonPath('data.status_id', 3);

        $this->assertSame([3], ApplicationLog::where('booking_id', $booking->id)->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_the_approver_rejects_a_transportation_booking_without_waiting_for_the_control_officer(): void
    {
        $booking = $this->booking(['booking_type_id' => 8]);

        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 4, 'version' => 1, 'comment' => 'אין אפשרות לביצוע'])->assertOk()->assertJsonPath('data.status_id', 4);

        $this->assertSame([4], ApplicationLog::where('booking_id', $booking->id)->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('אין אפשרות לביצוע', Comment::where('booking_id', $booking->id)->value('comment'));
    }

    public function test_the_control_officer_has_nothing_to_do_with_the_other_booking_types(): void
    {
        $officer = $this->controlOfficer();
        $this->booking();

        $this->as($officer)->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)->assertNotFound();
        $this->as($officer)->postJson(self::STATUS_URL, ['status_id' => 17, 'version' => 1])->assertNotFound();
    }

    public function test_the_approver_decides_once_the_vice_approver_has_passed_the_booking_on(): void
    {
        $this->booking(['status_id' => 15]);

        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonPath('data.actions.0.status_id', 3)
            ->assertJsonPath('data.actions.1.status_id', 4)
            ->assertJsonPath('data.actions.1.comment_required', true);

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status_id', 3);

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 5, 'version' => 2])
            ->assertOk()
            ->assertJsonPath('data.status_id', 5);

        // Closed: nothing more to do.
        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 7, 'version' => 3, 'comment' => 'ביטול'])
            ->assertForbidden();
    }

    public function test_a_rejection_needs_a_hebrew_comment_and_the_comment_is_saved(): void
    {
        $this->booking(['status_id' => 15]);

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 4, 'version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'יש לצרף הערה לביצוע פעולה זו');

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 4, 'version' => 1, 'comment' => 'english text'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'ההערה יכולה להכיל אותיות בעברית ורווחים בלבד');

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 4, 'version' => 1, 'comment' => 'חסר מידע'])
            ->assertOk();

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(4, (int) $booking->status_id);
        $this->assertSame('חסר מידע', Comment::where('booking_id', $booking->id)->value('comment'));
    }

    public function test_the_requesters_own_cancellation_is_left_to_the_mobile_app(): void
    {
        $this->booking(['status_id' => 15]);

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 6, 'version' => 1])
            ->assertForbidden();

        $this->assertDatabaseHas('audit_log', ['action' => 'booking.status_change', 'result' => 'denied', 'reason' => 'requester_only']);
    }

    public function test_a_stale_version_is_refused_with_the_reason(): void
    {
        $this->booking();

        $this->as(self::VICE_APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 7])
            ->assertStatus(409)
            ->assertJsonPath('message', 'הקריאה עודכנה בינתיים (ייתכן שבאפליקציה). יש לרענן ולבדוק את המצב העדכני.');

        $this->assertSame(1, (int) Booking::where('serial_number', self::SERIAL)->value('status_id'));
    }

    public function test_the_same_idempotency_key_does_not_act_twice(): void
    {
        $this->booking();

        $first = $this->as(self::VICE_APPROVER)
            ->withHeader('Idempotency-Key', 'key-1')
            ->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])
            ->assertOk()
            ->json('data');

        $second = $this->as(self::VICE_APPROVER)
            ->withHeader('Idempotency-Key', 'key-1')
            ->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])
            ->assertOk()
            ->json('data');

        $this->assertSame($first, $second);
        $this->assertSame(1, ApplicationLog::where('status_id', 15)->count());
        $this->assertSame(2, Booking::where('serial_number', self::SERIAL)->value('version'));

        // The key belongs to that request: using it for another one is refused, not answered with the wrong response.
        $this->as(self::VICE_APPROVER)
            ->withHeader('Idempotency-Key', 'key-1')
            ->postJson(self::STATUS_URL, ['status_id' => 16, 'version' => 1, 'comment' => 'חסר מידע'])
            ->assertUnprocessable();
    }

    public function test_a_manager_of_another_unit_does_not_see_the_booking(): void
    {
        $this->booking();

        $other = User::factory()->create(['personal_number' => '1000099', 'unit_id' => 2]);
        $other->assignRole('approver');
        $other->givePermissionTo('approver_1');

        $this->as('1000099')
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertNotFound();

        $this->as('1000099')
            ->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])
            ->assertNotFound();
    }

    public function test_a_handler_sees_only_the_bookings_at_the_stage_they_handle(): void
    {
        $this->booking(['serial_number' => 800000001, 'status_id' => 1]);
        $this->booking(['serial_number' => 800000002, 'status_id' => 15]);

        $serials = $this->as(self::VICE_APPROVER)
            ->getJson('/api/bookings-approver-management/bookings?ids=800000001,800000002')
            ->assertOk()
            ->json('data.*.serial_number');

        $this->assertSame([800000001], $serials, 'the booking at the approver stage is not for the vice approver');

        $this->as(self::VICE_APPROVER)
            ->getJson('/api/bookings-approver-management/bookings/800000002')
            ->assertNotFound();

        // The approver (a domain commander) sees the whole domain, and the super admin everything.
        $this->as(self::APPROVER)
            ->getJson('/api/bookings-approver-management/bookings?ids=800000001,800000002&sort=serial_number&dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.serial_number', 800000001)
            ->assertJsonPath('data.1.serial_number', 800000002);
    }

    public function test_creating_a_booking_is_refused_and_audited(): void
    {
        $this->as(self::ADMIN)
            ->postJson('/api/bookings-approver-management/bookings', ['booking_type_id' => 1])
            ->assertStatus(405);

        $this->assertDatabaseHas('audit_log', ['action' => 'booking.create', 'result' => 'denied']);
    }

    public function test_creating_a_booking_by_type_is_refused_and_audited_too(): void
    {
        foreach ([self::ADMIN, self::SUPER_ADMIN] as $person) {
            $this->as($person)
                ->postJson('/api/bookings-approver-management/bookings/1/create', ['anything' => 1])
                ->assertStatus(405)
                ->assertHeader('Allow', 'GET');
        }

        $this->assertSame(2, AuditLog::where('action', 'booking.create')->where('result', 'denied')->count());
        $this->assertSame(0, Booking::where('serial_number', '>', 900000000)->count());
    }

    public function test_the_list_tells_how_long_a_booking_waits_and_whether_it_is_overdue(): void
    {
        $booking = $this->booking(['status_id' => 15, 'status_changed_at' => now()->subHours(3)]);
        DB::table('bookings')->where('id', $booking->id)->update(['created_at' => now()->subHours(10)]);

        $this->as(self::ADMIN)
            ->putJson('/api/bookings-admin-management/targets', ['booking_type_id' => 1, 'target_hours' => 8])
            ->assertOk();

        $row = $this->as(self::ADMIN)
            ->getJson('/api/bookings-approver-management/bookings?ids=800000001')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('approver', $row['stage']);
        $this->assertEqualsWithDelta(3.0, $row['stage_age_hours'], 0.2);
        $this->assertEqualsWithDelta(10.0, $row['total_age_hours'], 0.2);
        $this->assertEquals(8.0, $row['target_hours']);
        $this->assertTrue($row['overdue']);
    }

    //----------------------------- Values ------------------------------------//

    /** A booking at the approver's stage with a start date and start time the approver may correct (car booking fields 2 and 3). */
    private function bookingWithDateAndTime(): Booking
    {
        $booking = $this->booking(['status_id' => 15]);

        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 2, 'value' => now()->addDays(5)->toDateString()]);
        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 3, 'value' => '10:00']);

        return $booking;
    }

    public function test_the_approver_corrects_a_date_and_it_is_queued_for_the_mobile_app(): void
    {
        $booking = $this->bookingWithDateAndTime();
        $newDate = now()->addDays(9)->toDateString();

        $this->as(self::APPROVER)
            ->putJson('/api/bookings-approver-management/bookings/800000001/values', ['values' => ['2' => $newDate, '3' => '11:30'], 'version' => 1, 'reason' => 'תיאום מחדש'])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertSame($newDate, BookingValue::where('booking_id', $booking->id)->where('form_field_id', 2)->value('value'));
        $this->assertSame('11:30', BookingValue::where('booking_id', $booking->id)->where('form_field_id', 3)->value('value'));
        $this->assertTrue((bool) $booking->fresh()->is_updated_value);

        $file = SyncFile::where('kind', SyncFile::KIND_BOOKING_VALUES)->firstOrFail();
        $this->assertSame(SyncFile::STATE_QUEUED, $file->state);
        $this->assertSame(2, $file->record_count);
        $this->assertStringContainsString('SrvOnClick_r2b_booking_values_', $file->file_name);
        $this->assertSame(self::SERIAL, json_decode($file->payload, true)['changes'][0]['serial_number']);

        $audit = AuditLog::where('action', 'booking.values.update')->firstOrFail();
        $this->assertSame('תיאום מחדש', $audit->reason);
    }

    public function test_a_corrected_value_must_be_valid_and_in_an_editable_field(): void
    {
        $this->bookingWithDateAndTime();
        $url = '/api/bookings-approver-management/bookings/800000001/values';

        BookingRestrictionDate::create(['booking_type_id' => 1, 'date' => now()->addDays(20)->toDateString()]);

        $this->as(self::APPROVER)->putJson($url, ['values' => ['2' => now()->subDay()->toDateString()], 'version' => 1])
            ->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'לא ניתן לקבוע תאריך שכבר עבר'));

        $this->as(self::APPROVER)->putJson($url, ['values' => ['2' => now()->addDays(20)->toDateString()], 'version' => 1])
            ->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'התאריך חסום'));

        $this->as(self::APPROVER)->putJson($url, ['values' => ['3' => '25:99'], 'version' => 1])
            ->assertUnprocessable();

        // Field 1 of the car booking is not an editable one.
        $this->as(self::APPROVER)->putJson($url, ['values' => ['1' => '2026-12-01'], 'version' => 1])
            ->assertUnprocessable()->assertJsonPath('message', 'השדה אינו ניתן לעדכון במהלך הטיפול');

        // Saying nothing new is not a change.
        $same = BookingValue::where('form_field_id', 3)->value('value');
        $this->as(self::APPROVER)->putJson($url, ['values' => ['3' => $same], 'version' => 1])
            ->assertUnprocessable()->assertJsonPath('message', 'לא בוצע שינוי');

        $this->assertSame(1, Booking::where('serial_number', self::SERIAL)->value('version'));
    }

    public function test_values_are_corrected_by_whoever_has_the_turn_and_by_the_admin(): void
    {
        $this->bookingWithDateAndTime();
        $url = '/api/bookings-approver-management/bookings/800000001/values';

        // The booking is at the approver's stage: the vice approver (a handler) does not even see it, and the admin holds the turn.
        $this->as(self::VICE_APPROVER)->putJson($url, ['values' => ['3' => '12:00'], 'version' => 1])->assertNotFound();
        $this->as(self::ADMIN)->putJson($url, ['values' => ['3' => '12:00'], 'version' => 1])->assertOk();

        // Back at the vice approver's stage: the approver sees the booking but has no turn; the vice approver has.
        Booking::where('serial_number', self::SERIAL)->update(['status_id' => 1]);
        $this->as(self::APPROVER)->putJson($url, ['values' => ['3' => '13:00'], 'version' => 2])->assertForbidden();
        $this->as(self::VICE_APPROVER)->putJson($url, ['values' => ['3' => '13:00'], 'version' => 2])->assertOk();
    }

    //----------------------------- Audit -------------------------------------//

    public function test_the_audit_chain_is_valid_and_a_changed_row_breaks_it(): void
    {
        $this->booking();
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertOk();
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 2])->assertOk();

        $this->assertTrue(app(AuditService::class)->verify()['valid']);
        $this->assertGreaterThanOrEqual(2, AuditLog::count());

        // The database refuses a change (trigger) and so does the model.
        try {
            DB::table('audit_log')->update(['action' => 'tampered']);
            $this->fail('the audit log accepted an UPDATE');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        AuditLog::query()->firstOrFail()->update(['action' => 'tampered']);
    }

    public function test_a_row_changed_behind_the_triggers_back_is_found_by_the_chain(): void
    {
        $this->booking();
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertOk();

        DB::unprepared('DROP TRIGGER audit_log_no_update');
        DB::table('audit_log')->where('action', 'booking.status_change')->update(['reason' => 'rewritten']);

        $result = app(AuditService::class)->verify();

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['broken_at']);
    }

    public function test_rows_removed_from_the_end_of_the_log_are_found_too(): void
    {
        $this->booking();
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertOk();

        DB::unprepared('DROP TRIGGER audit_log_no_delete');
        DB::table('audit_log')->orderByDesc('id')->limit(1)->delete();

        $this->assertFalse(app(AuditService::class)->verify()['valid']);
    }
}

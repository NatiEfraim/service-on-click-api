<?php

namespace Tests\Feature;

use App\Enums\HttpStatusCodeEnum;
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

    public function test_the_admin_only_views_and_the_approver_waits_for_the_vice_approver(): void
    {
        $this->booking();

        $this->as(self::ADMIN)
            ->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])
            ->assertForbidden();

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 1])
            ->assertForbidden();

        $this->as(self::ADMIN)
            ->getJson('/api/bookings-approver-management/bookings/' . self::SERIAL)
            ->assertOk()
            ->assertJsonPath('data.actions', []);

        $this->assertSame(1, (int) Booking::where('serial_number', self::SERIAL)->value('status_id'));
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

    public function test_only_the_role_whose_turn_it_is_corrects_values(): void
    {
        $this->bookingWithDateAndTime();
        $body = ['values' => ['3' => '12:00'], 'version' => 1];

        // The booking is at the approver's stage: the vice approver (a handler) does not even see it, and the admin sees it but has no turn.
        $this->as(self::VICE_APPROVER)->putJson('/api/bookings-approver-management/bookings/800000001/values', $body)->assertNotFound();
        $this->as(self::ADMIN)->putJson('/api/bookings-approver-management/bookings/800000001/values', $body)->assertForbidden();
        $this->as(self::APPROVER)->putJson('/api/bookings-approver-management/bookings/800000001/values', $body)->assertOk();
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

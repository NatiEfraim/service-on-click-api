<?php

namespace Tests\Feature;

use App\Models\ApplicationLog;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\SyncConflict;
use App\Models\SyncFile;
use App\Models\User;
use App\Services\BookingStatusExportService;
use App\Services\ImportBookingService;
use Illuminate\Support\Facades\Storage;

/**
 * The traffic with the mobile app: a booking arrives in a b2r file, a person decides here, the decision goes back as an r2b file, the mobile
 * app confirms it - or changes the booking in the meantime, and a person settles the conflict. The bucket is a folder in memory.
 */
class SyncExportTest extends ImportJsonTestCase
{
    private const SERIAL = 5001;
    private const STATUS_URL = '/api/bookings-approver-management/bookings/5001/status';
    private const SYNC = '/api/bookings-super-admin-management/sync';

    protected function setUp(): void
    {
        parent::setUp();

        // The mobile app knows the people who decide by their ID number.
        User::where('personal_number', self::VICE_APPROVER)->update(['personal_id' => '123456789']);
        User::where('personal_number', self::APPROVER)->update(['personal_id' => '223456789']);
    }

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** Import a food booking (type 11, unit H) in status 1 from a b2r file. */
    private function importBooking(int $serial = self::SERIAL): Booking
    {
        $this->putBookingFile([$this->foodBooking($serial)]);
        app(ImportBookingService::class)->importFromBucket(sync: true);

        return Booking::where('serial_number', $serial)->firstOrFail();
    }

    /** Import a change record of the mobile app (a later status of a booking saved before, and the values it brings). @param ?array<int, array<string, mixed>> $values */
    private function importChange(int $serial, int $statusId, int $updatedAt, ?array $values = null): void
    {
        $this->putBookingFile([$this->changeRecord($serial, $statusId, $updatedAt, $values)], 'SrvOnClick_b2r_export_bookings_2026-10-30.json');
        app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    private function viceApproverPassesItOn(int $version = 1): void
    {
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => $version])->assertOk();
    }

    private function statusFile(): ?array
    {
        $files = Storage::disk('minio')->files('export-json/booking-status');

        return $files === [] ? null : ['name' => basename($files[0]), 'content' => json_decode(Storage::disk('minio')->get($files[0]), true)];
    }

    //----------------------------- Export ------------------------------------//

    public function test_a_decision_goes_back_to_the_mobile_app_as_a_cumulative_daily_file(): void
    {
        $booking = $this->importBooking();
        $this->assertTrue($booking->is_status_exported, 'an imported booking has nothing to send');

        $this->viceApproverPassesItOn();
        $this->assertFalse($booking->fresh()->is_status_exported);

        $result = app(BookingStatusExportService::class)->run();
        $this->assertTrue($result['status']['sent']);

        $file = $this->statusFile();
        $this->assertSame('SrvOnClick_r2b_booking_status_' . now()->toDateString() . '.json', $file['name']);
        $this->assertCount(1, $file['content']['bookings']);
        $this->assertSame(self::SERIAL, $file['content']['bookings'][0]['serial_number']);
        $this->assertSame(15, $file['content']['bookings'][0]['status_id']);
        $this->assertSame('123456789', $file['content']['bookings'][0]['personal_id'], 'the ID number of whoever decided');
        $this->assertMatchesRegularExpression('/^\d+$/', $file['content']['bookings'][0]['updated_at']);

        $this->assertTrue($booking->fresh()->is_status_exported);
        $this->assertSame(SyncFile::STATE_SENT, SyncFile::firstOrFail()->state);

        // Nothing new: the file is not written again.
        $this->assertSame('nothing_new', app(BookingStatusExportService::class)->run()['status']['reason']);
        $this->assertSame(1, SyncFile::count());
    }

    public function test_the_snapshot_holds_every_decision_the_mobile_app_has_not_confirmed(): void
    {
        $this->importBooking(5001);
        $this->putBookingFile([$this->foodBooking(5002)], 'SrvOnClick_b2r_export_bookings_2026-10-31.json');
        app(ImportBookingService::class)->importFromBucket(sync: true);

        $this->viceApproverPassesItOn();
        app(BookingStatusExportService::class)->run();

        $this->as(self::VICE_APPROVER)->postJson('/api/bookings-approver-management/bookings/5002/status', ['status_id' => 15, 'version' => 1])->assertOk();
        app(BookingStatusExportService::class)->run();

        // The second run rewrote today's file: both decisions are in it.
        $serials = collect($this->statusFile()['content']['bookings'])->pluck('serial_number')->sort()->values()->all();
        $this->assertSame([5001, 5002], $serials);
        $this->assertSame(2, SyncFile::count());
    }

    public function test_a_status_the_mobile_app_shows_back_confirms_the_decision_and_it_leaves_the_snapshot(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();
        app(BookingStatusExportService::class)->run();

        // The mobile app imported the file and now exports the same status.
        $this->importChange(self::SERIAL, 15, time() + 60);

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(15, (int) $booking->ext_status_id);
        $this->assertSame('external', $booking->status_source, 'nothing is waiting any more');
        $this->assertSame(0, SyncConflict::count());

        // A decision on another booking is still sent; this one is not in the file any more.
        $this->putBookingFile([$this->foodBooking(5002)], 'SrvOnClick_b2r_export_bookings_2026-10-31.json');
        app(ImportBookingService::class)->importFromBucket(sync: true);
        $this->as(self::VICE_APPROVER)->postJson('/api/bookings-approver-management/bookings/5002/status', ['status_id' => 15, 'version' => 1])->assertOk();
        app(BookingStatusExportService::class)->run();

        $this->assertSame([5002], collect($this->statusFile()['content']['bookings'])->pluck('serial_number')->all());
    }

    public function test_a_value_correction_is_sent_as_its_own_file(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        // Field 9 is the supply date of an in-unit food booking; it is the approver's turn now.
        $date = now()->addDays(9)->toDateString();
        $this->as(self::APPROVER)
            ->putJson('/api/bookings-approver-management/bookings/5001/values', ['values' => ['9' => $date], 'version' => 2])
            ->assertOk();

        $this->assertTrue(Booking::where('serial_number', self::SERIAL)->value('is_updated_value') == 1);

        $result = app(BookingStatusExportService::class)->run();
        $this->assertSame(1, $result['values']['sent']);

        $files = Storage::disk('minio')->files('export-json/booking-values');
        $this->assertCount(1, $files);
        $this->assertSame($date, json_decode(Storage::disk('minio')->get($files[0]), true)['changes'][0]['value']);
        $this->assertFalse((bool) Booking::where('serial_number', self::SERIAL)->value('is_updated_value'));
    }

    public function test_nothing_is_written_while_the_export_is_switched_off(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        config(['app.export_json.enabled' => false]);

        $this->assertSame(['enabled' => false], app(BookingStatusExportService::class)->run());
        $this->assertSame([], Storage::disk('minio')->allFiles('export-json'));
        $this->assertFalse(Booking::where('serial_number', self::SERIAL)->firstOrFail()->is_status_exported, 'the decision waits');
    }

    public function test_a_bucket_that_is_down_is_retried_with_a_back_off_and_a_person_can_send_the_file_again(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        config(['filesystems.storage_service' => 'no-such-disk']);

        $first = app(BookingStatusExportService::class)->run()['status'];
        $this->assertFalse($first['sent']);

        $file = SyncFile::firstOrFail();
        $this->assertSame(SyncFile::STATE_FAILED, $file->state);
        $this->assertSame(1, $file->attempts);
        $this->assertNotNull($file->next_attempt_at);
        $this->assertFalse(Booking::where('serial_number', self::SERIAL)->firstOrFail()->is_status_exported, 'still waiting');

        // The next round waits out the back-off instead of hammering the bucket.
        $this->assertSame('waiting_to_retry', app(BookingStatusExportService::class)->run()['status']['reason']);
        $this->assertSame(1, $file->fresh()->attempts);

        // The bucket is back; the super admin sends the file again.
        config(['filesystems.storage_service' => 'minio']);
        $this->as(self::SUPER_ADMIN)->postJson(self::SYNC . "/files/{$file->id}/retry")->assertOk()->assertJsonPath('data.sent', true);
        $this->assertSame(SyncFile::STATE_SENT, $file->fresh()->state);
        $this->assertNotNull($this->statusFile());

        // Only the super admin sends by hand, and a file that went is not sent twice.
        $this->as(self::ADMIN)->postJson(self::SYNC . "/files/{$file->id}/retry")->assertForbidden();
        $this->as(self::SUPER_ADMIN)->postJson(self::SYNC . "/files/{$file->id}/retry")->assertStatus(409);
    }

    public function test_the_command_sends_what_is_waiting(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        $this->artisan('exportBookingStatusJson:mysql')->assertExitCode(0);

        $this->assertNotNull($this->statusFile());
    }

    //----------------------------- Conflicts ---------------------------------//

    /** A decision is waiting (status 15), and the mobile app moves the booking to a closing status (4) before it confirms it: both moved. */
    private function conflict(): SyncConflict
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();
        $this->importChange(self::SERIAL, 4, time() + 120);

        return SyncConflict::firstOrFail();
    }

    public function test_a_change_that_crosses_a_waiting_decision_is_held_for_a_person_and_never_applied_silently(): void
    {
        $conflict = $this->conflict();
        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();

        $this->assertSame(SyncConflict::STATE_OPEN, $conflict->state);
        $this->assertSame(15, (int) $conflict->internal_status_id);
        $this->assertSame(4, (int) $conflict->external_status_id);
        $this->assertSame(1, (int) $conflict->base_status_id);
        $this->assertSame(15, (int) $booking->status_id, 'our decision still stands');
        $this->assertSame(4, (int) $booking->ext_status_id);

        $this->assertDatabaseHas('audit_log', ['action' => 'sync.conflict.detected', 'booking_serial' => self::SERIAL]);

        // The same row again opens nothing twice.
        $this->importChange(self::SERIAL, 4, time() + 120);
        $this->assertSame(1, SyncConflict::count());

        // The decision is not sent while it is in conflict.
        $result = app(BookingStatusExportService::class)->run();
        $this->assertSame('no_decisions', $result['status']['reason']);
        $this->assertNull($this->statusFile());
    }

    public function test_while_a_conflict_is_open_the_booking_cannot_be_changed_and_says_so(): void
    {
        $conflict = $this->conflict();

        $this->as(self::APPROVER)
            ->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 2])
            ->assertStatus(409)
            ->assertJsonPath('message', 'קיימת התנגשות סנכרון פתוחה לקריאה זו. יש ליישב אותה לפני פעולה נוספת.');

        $row = $this->as(self::APPROVER)->getJson('/api/bookings-approver-management/bookings/5001')->assertOk()->json('data');
        $this->assertTrue($row['has_conflict']);
        $this->assertSame('conflict', $row['sync_state']);
        // The detail names the conflict to settle; its statuses are on the sync screen's list.
        $this->assertSame(['id' => $conflict->id], $row['sync']['open_conflict']);
    }

    public function test_the_super_admin_accepts_the_status_of_the_mobile_app(): void
    {
        $conflict = $this->conflict();

        $this->as(self::SUPER_ADMIN)
            ->postJson(self::SYNC . "/conflicts/{$conflict->id}/resolve", ['resolution' => 'accept_external', 'reason' => 'המזמין ביטל'])
            ->assertOk()
            ->assertJsonPath('data.status_id', 4);

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(4, (int) $booking->status_id);
        $this->assertSame('external', $booking->status_source);
        $this->assertTrue($booking->is_status_exported, 'nothing to send: the mobile app has it');

        $conflict->refresh();
        $this->assertSame(SyncConflict::STATE_RESOLVED, $conflict->state);
        $this->assertSame('המזמין ביטל', $conflict->resolution_reason);

        $this->assertDatabaseHas('audit_log', ['action' => 'sync.conflict.resolve', 'reason' => 'המזמין ביטל']);
        $this->assertSame(1, ApplicationLog::where('booking_id', $booking->id)->where('status_id', 4)->count());

        // Settled once.
        $this->as(self::SUPER_ADMIN)
            ->postJson(self::SYNC . "/conflicts/{$conflict->id}/resolve", ['resolution' => 'accept_external', 'reason' => 'שוב'])
            ->assertStatus(409);
    }

    public function test_the_requesters_own_cancellation_cannot_be_overruled(): void
    {
        $conflict = $this->conflict();
        // (The import applies a cancellation by the requester by itself, so no conflict is opened for it; this is a conflict that holds one.)
        $conflict->update(['external_status_id' => 6]);

        $this->as(self::SUPER_ADMIN)
            ->postJson(self::SYNC . "/conflicts/{$conflict->id}/resolve", ['resolution' => 'keep_internal', 'reason' => 'אני מתעקש'])
            ->assertUnprocessable();

        $this->assertSame(SyncConflict::STATE_OPEN, $conflict->fresh()->state);
        $this->assertSame(15, (int) Booking::where('serial_number', self::SERIAL)->value('status_id'));
    }

    public function test_the_super_admin_keeps_the_decision_made_here_and_it_is_sent_again(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();
        // The mobile app moved the booking to a technical status in the meantime (not the requester's own cancellation).
        $this->importChange(self::SERIAL, 4, time() + 120);
        $conflict = SyncConflict::firstOrFail();

        $this->as(self::SUPER_ADMIN)
            ->postJson(self::SYNC . "/conflicts/{$conflict->id}/resolve", ['resolution' => 'keep_internal', 'reason' => 'ההחלטה שלנו נשארת'])
            ->assertOk();

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(15, (int) $booking->status_id);
        $this->assertFalse($booking->is_status_exported, 'sent again');

        app(BookingStatusExportService::class)->run();
        $this->assertSame([5001], collect($this->statusFile()['content']['bookings'])->pluck('serial_number')->all());
    }

    public function test_a_reason_is_required_and_only_the_super_admin_decides(): void
    {
        $conflict = $this->conflict();
        $url = self::SYNC . "/conflicts/{$conflict->id}/resolve";

        $this->as(self::SUPER_ADMIN)->postJson($url, ['resolution' => 'accept_external'])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->postJson($url, ['resolution' => 'something-else', 'reason' => 'x'])->assertUnprocessable();
        $this->as(self::ADMIN)->postJson($url, ['resolution' => 'accept_external', 'reason' => 'x'])->assertForbidden();
        $this->as(self::APPROVER)->postJson($url, ['resolution' => 'accept_external', 'reason' => 'x'])->assertForbidden();
    }

    //----------------------------- What needs no decision --------------------//

    public function test_the_requester_cancelling_while_a_decision_waits_is_applied_without_a_conflict(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        // The cancellation was made before our decision (its time is older): it stands all the same.
        $this->importChange(self::SERIAL, 6, time() - 3600);

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(6, (int) $booking->status_id);
        $this->assertSame('external', $booking->status_source);
        $this->assertTrue($booking->is_status_exported, 'nothing of it is sent back');
        $this->assertSame(3, $booking->version, 'it moved the booking like any change');
        $this->assertSame(0, SyncConflict::count());

        // The decision that was overridden is in the audit log, and not in the next snapshot.
        $this->assertDatabaseHas('audit_log', ['action' => 'sync.conflict.auto_resolve', 'booking_serial' => self::SERIAL]);
        $this->assertSame('no_decisions', app(BookingStatusExportService::class)->run()['status']['reason']);

        // The same record again changes nothing.
        $this->importChange(self::SERIAL, 6, time() - 3600);
        $this->assertSame(3, Booking::where('serial_number', self::SERIAL)->value('version'));
    }

    public function test_a_booking_approved_here_and_completed_in_the_app_is_not_a_conflict(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => 2])->assertOk();

        $this->importChange(self::SERIAL, 5, time() + 300);

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(5, (int) $booking->status_id);
        $this->assertSame(0, SyncConflict::count());
    }

    public function test_an_approval_of_the_app_older_than_our_decision_is_a_conflict_and_not_dropped(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        // The app says "approved" with a time before our decision: it is not a stale echo of the old status, somebody approved.
        $this->importChange(self::SERIAL, 3, time() - 3600);

        $this->assertSame(1, SyncConflict::count());
        $this->assertSame(3, (int) SyncConflict::firstOrFail()->external_status_id);
        $this->assertSame(15, (int) Booking::where('serial_number', self::SERIAL)->value('status_id'));
    }

    public function test_a_conflict_closes_by_itself_when_the_app_comes_to_show_the_decision_made_here(): void
    {
        $conflict = $this->conflict();

        // The app takes our decision after all.
        $this->importChange(self::SERIAL, 15, time() + 600);

        $conflict->refresh();
        $this->assertSame(SyncConflict::STATE_RESOLVED, $conflict->state);
        $this->assertSame(SyncConflict::CONFIRMED_BY_MOBILE, $conflict->resolution);
        $this->assertDatabaseHas('audit_log', ['action' => 'sync.conflict.auto_resolve', 'booking_serial' => self::SERIAL]);

        $booking = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertSame(15, (int) $booking->status_id);
        $this->assertSame('external', $booking->status_source);

        // Nothing holds the booking any more: the approver can decide.
        $this->as(self::APPROVER)->postJson(self::STATUS_URL, ['status_id' => 3, 'version' => (int) $booking->version])->assertOk();
    }

    public function test_a_newer_record_with_the_same_status_does_not_restart_the_stage(): void
    {
        $this->importBooking();
        $changedAt = Booking::where('serial_number', self::SERIAL)->firstOrFail()->status_changed_at;

        // The app touched the booking again (a comment, say) and sent it with the status it already had.
        $this->importChange(self::SERIAL, 1, self::CREATED_AT + 86400);

        $after = Booking::where('serial_number', self::SERIAL)->firstOrFail();
        $this->assertEquals($changedAt, $after->status_changed_at, 'the stage keeps counting from when it began');
        $this->assertSame(1, $after->version);
    }

    public function test_a_screen_that_is_out_of_date_gets_a_409_before_a_refusal_of_the_turn(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        // Another screen of the same person still shows version 1; the booking has gone on to the approver since.
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 1])->assertStatus(409);
        $this->as(self::VICE_APPROVER)
            ->putJson('/api/bookings-approver-management/bookings/5001/values', ['values' => ['9' => now()->addDays(9)->toDateString()], 'version' => 1])
            ->assertStatus(409);

        // With the current version it is what it is: not their turn any more.
        $this->as(self::VICE_APPROVER)->postJson(self::STATUS_URL, ['status_id' => 15, 'version' => 2])->assertNotFound();
    }

    //----------------------------- Values in the traffic ---------------------//

    public function test_a_correction_waiting_to_be_sent_is_not_taken_back_by_an_older_record_of_the_app(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        $date = now()->addDays(9)->toDateString();
        $this->as(self::APPROVER)
            ->putJson('/api/bookings-approver-management/bookings/5001/values', ['values' => ['9' => $date], 'version' => 2])
            ->assertOk();

        // A record the app wrote before it saw the correction still carries the old date.
        $this->importChange(self::SERIAL, 15, time() + 60, [$this->node(9, null, 3, '2026-11-09')]);

        $bookingId = Booking::where('serial_number', self::SERIAL)->value('id');
        $this->assertSame($date, BookingValue::where('booking_id', $bookingId)->where('form_field_id', 9)->firstOrFail()->value);
        $this->assertSame(3, Booking::where('serial_number', self::SERIAL)->value('version'), 'nothing was written, so the version stays');
    }

    public function test_a_value_the_app_changed_moves_the_version(): void
    {
        $this->importBooking();

        // The amount (field 8) was 50.
        $this->importChange(self::SERIAL, 1, self::CREATED_AT + 60, [$this->node(8, null, 1, '60')]);

        $this->assertSame(2, Booking::where('serial_number', self::SERIAL)->value('version'), 'whoever has the booking open is told by a 409');
    }

    //----------------------------- Export, day by day ------------------------//

    public function test_a_decision_still_waiting_the_next_day_is_written_again_under_that_days_name(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        $this->assertTrue(app(BookingStatusExportService::class)->run()['status']['sent']);

        // The app has not confirmed it by tomorrow, and its importer reads only the file named for ITS today.
        $this->travelTo(now()->addDay());
        $second = app(BookingStatusExportService::class)->run()['status'];

        $this->assertTrue($second['sent'], 'a file was written, not yesterday\'s reused');
        $this->assertSame(2, SyncFile::count());

        $names = collect(Storage::disk('minio')->files('export-json/booking-status'))->map(fn (string $path): string => basename($path))->sort()->values()->all();
        $this->assertSame([
            'SrvOnClick_r2b_booking_status_' . now()->subDay()->toDateString() . '.json',
            'SrvOnClick_r2b_booking_status_' . now()->toDateString() . '.json',
        ], $names);

        // And the same day again writes nothing more.
        $this->assertSame('nothing_new', app(BookingStatusExportService::class)->run()['status']['reason']);
    }

    public function test_a_snapshot_that_failed_before_midnight_is_replaced_by_the_next_days_file(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        config(['filesystems.storage_service' => 'no-such-disk']);
        $this->assertFalse(app(BookingStatusExportService::class)->run()['status']['sent']);
        $failed = SyncFile::firstOrFail();

        config(['filesystems.storage_service' => 'minio']);
        $this->travelTo(now()->addDay());
        $this->assertTrue(app(BookingStatusExportService::class)->run()['status']['sent']);

        $this->assertSame(SyncFile::STATE_SUPERSEDED, $failed->fresh()->state, 'it is not left to be retried by hand under yesterday\'s name');
        $this->assertNull($failed->fresh()->next_attempt_at);
        $this->as(self::SUPER_ADMIN)->postJson(self::SYNC . "/files/{$failed->id}/retry")->assertStatus(409);
    }

    public function test_a_later_correction_of_a_booking_never_goes_out_before_an_earlier_one_that_failed(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        $url = '/api/bookings-approver-management/bookings/5001/values';
        $this->as(self::APPROVER)->putJson($url, ['values' => ['9' => now()->addDays(9)->toDateString()], 'version' => 2])->assertOk();
        $this->as(self::APPROVER)->putJson($url, ['values' => ['9' => now()->addDays(10)->toDateString()], 'version' => 3])->assertOk();
        $this->assertSame(2, SyncFile::where('kind', SyncFile::KIND_BOOKING_VALUES)->count());

        // The bucket is down: the first correction fails and waits out its back-off.
        config(['filesystems.storage_service' => 'no-such-disk']);
        $this->assertSame(0, app(BookingStatusExportService::class)->run()['values']['sent']);

        // The bucket is back, but the first file is still waiting: the second must not overtake it.
        config(['filesystems.storage_service' => 'minio']);
        $this->assertSame(0, app(BookingStatusExportService::class)->run()['values']['sent']);
        $this->assertSame([], Storage::disk('minio')->allFiles('export-json/booking-values'));

        // After the wait both go - two files, even though they were made in the same second.
        $this->travel(10)->minutes();
        $this->assertSame(2, app(BookingStatusExportService::class)->run()['values']['sent']);
        $this->assertCount(2, Storage::disk('minio')->allFiles('export-json/booking-values'));
    }

    //----------------------------- Overview ----------------------------------//

    public function test_the_overview_shows_the_health_the_files_the_conflicts_and_the_decisions_waiting(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();

        $before = $this->as(self::SUPER_ADMIN)->getJson(self::SYNC)->assertOk()->json('data');
        $this->assertSame(1, $before['health']['pending_decisions']);
        $this->assertSame(self::SERIAL, $before['pending_decisions'][0]['booking_serial']);
        $this->assertTrue($before['can_operate']);

        $this->as(self::SUPER_ADMIN)->postJson(self::SYNC . '/run')->assertOk()->assertJsonPath('data.status.sent', true);

        $after = $this->as(self::SUPER_ADMIN)->getJson(self::SYNC)->assertOk()->json('data');
        $this->assertSame(0, $after['health']['pending_decisions']);
        $this->assertNotNull($after['health']['last_sent_at']);
        $this->assertSame('booking_status', $after['files'][0]['kind']);
        $this->assertArrayNotHasKey('payload', $after['files'][0]);

        $detail = $this->as(self::SUPER_ADMIN)->getJson(self::SYNC . '/files/' . $after['files'][0]['id'])->assertOk()->json('data');
        $this->assertSame(self::SERIAL, $detail['records'][0]['serial_number']);

        $this->as(self::ADMIN)->getJson(self::SYNC)->assertForbidden();
    }

    public function test_the_audit_log_does_not_show_the_transfer_events(): void
    {
        $this->importBooking();
        $this->viceApproverPassesItOn();
        app(BookingStatusExportService::class)->run();

        $this->assertGreaterThan(0, AuditLog::where('action', 'like', 'sync.%')->count(), 'kept in the log');

        $actions = collect($this->as(self::SUPER_ADMIN)->getJson('/api/bookings-super-admin-management/audit')->assertOk()->json('data'))->pluck('action');

        $this->assertContains('booking.status_change', $actions);
        $this->assertSame(0, $actions->filter(fn (string $action) => str_starts_with($action, 'sync.'))->count());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\ApplicationLog;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\SyncConflict;
use App\Models\SyncFile;
use App\Models\User;
use App\Services\BookingStatusExportService;
use App\Services\BookingStatusSnapshotService;
use App\Services\ImportBookingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Putting a transportation booking on hold (8 waiting for files, 9 for the supplier's approval, 10 for the supplier's number): the booking does not
 * move. It stays with the stage it was put on hold from - the control officer's, the vice approver's or the approver's - while the hold is what
 * it shows and what the next round of the r2b status file carries to the mobile app.
 */
class TransportationHoldTest extends HatchTestCase
{
    private const SERIAL = 5801;
    private const URL = '/api/bookings-approver-management/bookings/5801';
    private const LIST = '/api/bookings-approver-management/bookings';

    private const OFFICER = '1000008';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** A transportation booking (type 8, unit H) as the mobile app left it. */
    private function booking(int $statusId = 1, array $attributes = []): Booking
    {
        return Booking::create($attributes + [
            'serial_number' => self::SERIAL,
            'unit_id' => 1,
            'booking_type_id' => 8,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => $statusId,
            'ext_status_id' => $statusId,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);
    }

    /** The control officer of transportation, known to the mobile app by their ID number. */
    private function controlOfficer(): string
    {
        $officer = User::factory()->create(['personal_number' => self::OFFICER, 'unit_id' => 1, 'personal_id' => '323456789']);
        $officer->assignRole(RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value);
        $officer->givePermissionTo('transportation_control_officer_8');

        return self::OFFICER;
    }

    private function move(string $person, int $statusId, int $version, ?string $comment = null, int $serial = self::SERIAL): TestResponse
    {
        return $this->as($person)->postJson("/api/bookings-approver-management/bookings/{$serial}/status", ['status_id' => $statusId, 'version' => $version] + ($comment === null ? [] : ['comment' => $comment]));
    }

    /** The booking's row in the list of this person, or null when it is not in their list. @return ?array<string, mixed> */
    private function row(string $person, int $serial = self::SERIAL): ?array
    {
        return $this->as($person)->getJson(self::LIST . '?ids=' . $serial)->assertOk()->json('data.0');
    }

    /** @return array<int, int> the status ids of the buttons this person has on the booking; null-safe for a booking they do not see */
    private function buttons(string $person, int $serial = self::SERIAL): ?array
    {
        $row = $this->row($person, $serial);

        return $row === null ? null : array_map('intval', array_column($row['actions'], 'status_id'));
    }

    /** @return array<int, int> the serial numbers a list of this person holds */
    private function listed(string $person, string $query): array
    {
        return array_map('intval', $this->as($person)->getJson(self::LIST . '?' . $query)->assertOk()->json('data.*.serial_number'));
    }

    //----------------------------- Every role: the hold keeps the booking where it is --------------------------------//

    public function test_the_control_officer_puts_it_on_hold_and_it_stays_with_the_control_officer(): void
    {
        $booking = $this->booking();
        $officer = $this->controlOfficer();

        $this->move($officer, 8, 1)->assertOk()->assertJsonPath('data.status_id', 8)->assertJsonPath('data.version', 2)->assertJsonPath('data.sync_state', 'pending');

        // What the booking shows is the hold. Where it is parked is remembered, and the decision waits to be sent to the mobile app.
        $booking = $booking->fresh();
        $this->assertSame(8, (int) $booking->status_id);
        $this->assertSame(1, (int) $booking->parked_status_id);
        $this->assertSame('internal', $booking->status_source);
        $this->assertSame(1, (int) $booking->ext_status_id, 'what the mobile app has is untouched');
        $this->assertFalse($booking->is_status_exported, 'the next round of the r2b status file carries it');

        // The stage did not change: the control officer still has it, with the other two holds and the answers.
        $row = $this->row($officer);
        $this->assertSame(8, $row['status_id']);
        $this->assertSame('control_officer', $row['stage']);
        $this->assertSame([17, 18, 9, 10, 19], $this->buttons($officer));
        $this->assertSame([self::SERIAL], $this->listed($officer, 'mine=1&ids=' . self::SERIAL), 'on their "waiting for me" list');
        $this->as($officer)->getJson(self::URL)->assertOk()->assertJsonPath('data.stage', 'control_officer');
        $this->assertTrue($this->mayEditValues($officer, self::SERIAL), 'a hold does not take the turn to correct the values away');

        // The others see the same stage: the vice approver may take it over, the approver and the admin may decide.
        $this->assertSame([15, 16, 9, 10, 19], $this->buttons(self::VICE_APPROVER));
        $this->assertSame([3, 4, 19], $this->buttons(self::APPROVER));
        $this->assertSame([17, 18, 9, 10, 19, 3, 4], $this->buttons(self::ADMIN));
        $this->assertSame([17, 18, 9, 10, 19, 3, 4], $this->buttons(self::SUPER_ADMIN));

        // From one hold to another it stays where it is parked; the hold it is on is not offered again.
        $this->move($officer, 8, 2)->assertForbidden();
        $this->move($officer, 9, 2)->assertOk();
        $this->assertSame(1, (int) $booking->fresh()->parked_status_id);
        $this->assertSame([17, 18, 8, 10, 19], $this->buttons($officer));

        // Resuming: the control officer answers as always, and from then on the booking is the vice approver's.
        $this->move($officer, 17, 3)->assertOk();
        $booking = $booking->fresh();
        $this->assertSame(17, (int) $booking->status_id);
        $this->assertNull($booking->parked_status_id, 'leaving the hold clears where it was parked');
        $this->assertNull($this->row($officer), 'the control officer is done with it');
        $this->assertSame('vice_approver', $this->row(self::VICE_APPROVER)['stage']);
        $this->assertSame([15, 16, 8, 9, 10, 19], $this->buttons(self::VICE_APPROVER));

        // The timeline names every step and the audit log says where the holds left the booking.
        $this->assertSame([8, 9, 17], ApplicationLog::where('booking_id', $booking->id)->orderBy('id')->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(
            [['status_id' => 8, 'version' => 2, 'parked_status_id' => 1], ['status_id' => 9, 'version' => 3, 'parked_status_id' => 1], ['status_id' => 17, 'version' => 4]],
            AuditLog::where('action', 'booking.status_change')->where('result', 'success')->orderBy('id')->get()->map(fn (AuditLog $row): array => $row->after)->all()
        );
        $this->assertSame('transition 8->8 not allowed', AuditLog::where('action', 'booking.status_change')->where('result', 'denied')->value('reason'), 'the same hold again was refused and audited');
    }

    public function test_the_vice_approver_puts_it_on_hold_and_it_stays_with_the_vice_approver(): void
    {
        $booking = $this->booking(17);
        $officer = $this->controlOfficer();

        $this->move(self::VICE_APPROVER, 9, 1)->assertOk()->assertJsonPath('data.status_id', 9);

        $this->assertSame(17, (int) $booking->fresh()->parked_status_id);
        $this->assertSame('vice_approver', $this->row(self::VICE_APPROVER)['stage']);
        $this->assertSame([15, 16, 8, 10, 19], $this->buttons(self::VICE_APPROVER));
        $this->assertNull($this->buttons($officer), 'the control officer had passed it on: still not theirs');
        $this->assertSame([3, 4, 19], $this->buttons(self::APPROVER), 'the approver may still decide without waiting');

        // It goes on to the approver only when the vice approver passes it on.
        $this->move(self::VICE_APPROVER, 15, 2)->assertOk();
        $this->assertNull($booking->fresh()->parked_status_id);
        $this->assertNull($this->buttons(self::VICE_APPROVER));
        $this->assertSame('approver', $this->row(self::APPROVER)['stage']);
    }

    public function test_the_approver_puts_it_on_hold_and_it_stays_with_the_approver(): void
    {
        $booking = $this->booking(15);

        $this->move(self::APPROVER, 10, 1)->assertOk()->assertJsonPath('data.status_id', 10);

        $this->assertSame(15, (int) $booking->fresh()->parked_status_id);
        $row = $this->row(self::APPROVER);
        $this->assertSame('approver', $row['stage'], 'it is no longer shown as waiting at a supplier stage');
        $this->assertSame([3, 4, 8, 9, 19], $this->buttons(self::APPROVER));
        $this->assertNull($this->buttons(self::VICE_APPROVER));

        $this->move(self::APPROVER, 3, 2)->assertOk();
        $this->assertNull($booking->fresh()->parked_status_id);
        $this->assertSame('realization', $this->row(self::APPROVER)['stage']);
    }

    public function test_the_admin_and_the_super_admin_put_it_on_hold_wherever_it_is_and_it_stays_there(): void
    {
        $booking = $this->booking();

        $this->move(self::ADMIN, 8, 1)->assertOk();
        $this->assertSame(1, (int) $booking->fresh()->parked_status_id);
        $this->assertSame('control_officer', $this->row(self::ADMIN)['stage']);

        $this->move(self::SUPER_ADMIN, 10, 2)->assertOk();
        $this->assertSame(1, (int) $booking->fresh()->parked_status_id, 'from one hold to another: still where it was parked');
        $this->assertSame([17, 18, 8, 9, 19, 3, 4], $this->buttons(self::SUPER_ADMIN));

        // A booking at the vice approver's stage stays there for the admin as well.
        $other = $this->booking(18, ['serial_number' => 5802]);
        $this->move(self::ADMIN, 9, 1, null, 5802)->assertOk();
        $this->assertSame(18, (int) $other->fresh()->parked_status_id);
        $this->assertSame('vice_approver', $this->row(self::ADMIN, 5802)['stage']);
    }

    public function test_the_vice_approver_who_takes_it_over_and_holds_it_leaves_it_at_the_control_officer(): void
    {
        $booking = $this->booking();
        $officer = $this->controlOfficer();

        $this->move(self::VICE_APPROVER, 8, 1)->assertOk();

        // The booking did not move up to the vice approver's own stage: it is still waiting at the control officer, who can carry on with it.
        $this->assertSame(1, (int) $booking->fresh()->parked_status_id);
        $this->assertSame('control_officer', $this->row(self::VICE_APPROVER)['stage']);
        $this->assertSame([17, 18, 9, 10, 19], $this->buttons($officer));
        $this->assertSame([15, 16, 9, 10, 19], $this->buttons(self::VICE_APPROVER));
    }

    public function test_a_hold_from_before_the_booking_remembered_where_it_was_parked_stays_at_the_supplier_stage(): void
    {
        $this->controlOfficer();
        $booking = $this->booking(8);

        $row = $this->row(self::APPROVER);
        $this->assertSame('supplier_files', $row['stage']);
        $this->assertSame([3, 4, 9, 10, 19], $this->buttons(self::APPROVER));
        $this->assertSame([3, 4, 9, 10, 19], $this->buttons(self::ADMIN));
        $this->assertNull($this->buttons(self::VICE_APPROVER));
        $this->assertNull($this->buttons(self::OFFICER));

        // Moved on to another hold it stays that way: nothing is invented for a booking that never had a place.
        $this->move(self::APPROVER, 9, 1)->assertOk();
        $this->assertNull($booking->fresh()->parked_status_id);
        $this->assertSame('supplier_approval', $this->row(self::APPROVER)['stage']);
    }

    public function test_a_hold_can_only_be_put_on_from_a_stage_and_never_on_a_closed_or_approved_booking(): void
    {
        $this->booking(3);
        $this->move(self::APPROVER, 8, 1)->assertForbidden();

        $this->booking(4, ['serial_number' => 5802]);
        $this->move(self::ADMIN, 9, 1, null, 5802)->assertForbidden();
        $this->assertNull(Booking::where('serial_number', 5802)->value('parked_status_id'));
    }

    //----------------------------- Lists, filters and figures follow the stage --------------------------------//

    public function test_a_booking_on_hold_is_listed_filtered_and_counted_in_the_stage_it_is_parked_at(): void
    {
        $officer = $this->controlOfficer();

        $this->booking(1, ['serial_number' => 5801]);                       // held by the control officer below: parked at the control officer
        $this->booking(9, ['serial_number' => 5802]);                       // an old hold, parked nowhere
        $this->booking(1, ['serial_number' => 5803]);                       // not on hold, at the control officer
        $this->booking(15, ['serial_number' => 5804]);                      // held by the approver below: parked at the approver
        $this->move($officer, 8, 1, null, 5801)->assertOk();
        $this->move(self::APPROVER, 10, 1, null, 5804)->assertOk();
        $ids = 'ids=5801,5802,5803,5804&sort=serial_number&dir=asc';

        // By stage: where it is parked, whatever the hold says.
        $this->assertSame([5801, 5803], $this->listed(self::APPROVER, "stage=control_officer&{$ids}"));
        $this->assertSame([5804], $this->listed(self::APPROVER, "stage=approver&{$ids}"));
        $this->assertSame([5802], $this->listed(self::APPROVER, "stage=supplier_approval&{$ids}"), 'only the old hold');
        $this->assertSame([], $this->listed(self::APPROVER, "stage=supplier_number&{$ids}"));

        // By status: the hold itself.
        $this->assertSame([5801], $this->listed(self::APPROVER, "statuses=8&{$ids}"));
        $this->assertSame([5801, 5802, 5804], $this->listed(self::APPROVER, "statuses=8,9,10&{$ids}"));

        // "Waiting for me": each handler has the bookings parked at a stage they act on, held or not.
        $this->assertSame([5801, 5803], $this->listed($officer, "mine=1&{$ids}"));
        $this->assertSame([5801, 5803], $this->listed(self::VICE_APPROVER, "mine=1&{$ids}"), 'the vice approver may take the control officer\'s stage over');
        $this->assertSame([5801, 5802, 5803, 5804], $this->listed(self::APPROVER, "mine=1&{$ids}"));

        // The figures count a held booking in its stage.
        $this->assertSame(['approver' => 1, 'control_officer' => 2, 'supplier_approval' => 1], collect($this->as(self::APPROVER)->getJson('/api/bookings-approver-management/overview')->assertOk()->json('data.by_type_stage.8'))->sortKeys()->all());
        $this->assertSame(['approver' => 1, 'control_officer' => 2, 'supplier_approval' => 1], collect($this->as(self::ADMIN)->getJson('/api/bookings-admin-management/home')->assertOk()->json('data.by_type_stage.8'))->sortKeys()->all());
        $this->assertSame(4, $this->as(self::APPROVER)->getJson('/api/bookings-approver-management/overview')->json('data.awaiting_me.count'));
    }

    //----------------------------- The mobile app changes a booking that is on hold --------------------------------//

    /** What the mobile app exports after it has seen a booking: a later record with the status it has now. */
    private function appShows(int $statusId, int $secondsFromNow, int $serial = self::SERIAL): void
    {
        $this->putBookingFile([$this->changeRecord($serial, $statusId, time() + $secondsFromNow)], 'SrvOnClick_b2r_export_bookings_2026-10-30.json');
        app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    public function test_when_the_mobile_app_moves_a_held_booking_to_another_hold_it_stays_parked_and_any_other_status_clears_it(): void
    {
        $booking = $this->booking();
        $officer = $this->controlOfficer();
        $this->move($officer, 8, 1)->assertOk();

        // The mobile app shows the hold we sent: nothing changes but the confirmation.
        $this->appShows(8, 60);
        $booking = $booking->fresh();
        $this->assertSame('external', $booking->status_source);
        $this->assertSame(1, (int) $booking->parked_status_id);

        // The app moves it from one hold to another (the files arrived, say): the booking is still with the control officer.
        $this->appShows(9, 120);
        $booking = $booking->fresh();
        $this->assertSame(9, (int) $booking->status_id);
        $this->assertSame(1, (int) $booking->parked_status_id);
        $this->assertSame([17, 18, 8, 10, 19], $this->buttons($officer));

        // Any other status is the app's own word on where the booking is: nothing is left parked.
        $this->appShows(1, 180);
        $booking = $booking->fresh();
        $this->assertSame(1, (int) $booking->status_id);
        $this->assertNull($booking->parked_status_id);
        $this->assertSame([17, 18, 8, 9, 10, 19], $this->buttons($officer));

        $this->move($officer, 10, $booking->version)->assertOk();
        $this->appShows(6, 240);
        $this->assertNull($booking->fresh()->parked_status_id, 'the requester canceled: closed');
        $this->assertNull($this->buttons($officer));
    }

    public function test_settling_a_conflict_over_a_held_booking_keeps_where_it_is_parked_or_clears_it(): void
    {
        $officer = $this->controlOfficer();
        $kept = $this->booking(1, ['serial_number' => 5801]);
        $accepted = $this->booking(1, ['serial_number' => 5802]);
        $this->move($officer, 8, 1)->assertOk();
        $this->move($officer, 8, 1, null, 5802)->assertOk();

        // The app moved both to a technical status in the meantime (not the requester's cancellation): a conflict only the super admin settles.
        $this->appShows(4, 120, 5801);
        $this->appShows(4, 120, 5802);
        $this->assertSame(2, SyncConflict::where('state', SyncConflict::STATE_OPEN)->count());
        $this->assertSame(1, (int) $kept->fresh()->parked_status_id, 'our decision stands, and so does where it is parked');

        $resolve = fn (Booking $booking, string $resolution) => $this->as(self::SUPER_ADMIN)->postJson(
            '/api/bookings-super-admin-management/sync/conflicts/' . SyncConflict::where('booking_id', $booking->id)->value('id') . '/resolve',
            ['resolution' => $resolution, 'reason' => 'הכרעה']
        )->assertOk();

        // Keeping our decision: the hold goes out again and the booking is still with the control officer.
        $resolve($kept, 'keep_internal');
        $kept = $kept->fresh();
        $this->assertSame(8, (int) $kept->status_id);
        $this->assertSame(1, (int) $kept->parked_status_id);
        $this->assertFalse($kept->is_status_exported);
        $this->assertSame([17, 18, 9, 10, 19], $this->buttons($officer));

        // Accepting the app's status: the booking is where the app says, and nothing is left parked.
        $resolve($accepted, 'accept_external');
        $accepted = $accepted->fresh();
        $this->assertSame(4, (int) $accepted->status_id);
        $this->assertNull($accepted->parked_status_id);
        $this->assertTrue($accepted->is_status_exported, 'nothing to send: the app has it');
    }

    //----------------------------- The hold goes to the mobile app with the next round --------------------------------//

    /** @return array<int, array<string, mixed>> the rows of the day's status file on the fake FTP, by serial number */
    private function localStatusRows(): array
    {
        $content = json_decode($this->ftp()->get(self::RED_TO_BLACK . $this->statusFileName()), true);

        return collect($content['bookings'])->keyBy('serial_number')->all();
    }

    public function test_a_hold_is_sent_to_the_mobile_app_with_the_next_round_of_the_digital_hatch_status_file(): void
    {
        $booking = $this->booking();
        $officer = $this->controlOfficer();

        $this->assertTrue($booking->fresh()->is_status_exported, 'the mobile app has the booking as it is');

        // The decision is saved with the flag at 0: the booking waits for the next round.
        $this->move($officer, 8, 1)->assertOk();
        $this->assertFalse($booking->fresh()->is_status_exported);

        $result = app(BookingStatusSnapshotService::class)->export();

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['marked']);
        $this->assertTrue($booking->fresh()->is_status_exported, 'sent, so it is marked');

        // The file carries the hold itself, the ID number of whoever put it, and not where the booking is parked here (the app has no use for it).
        $row = $this->localStatusRows()[self::SERIAL];
        $this->assertSame(['serial_number', 'status_id', 'personal_id', 'updated_at'], array_keys($row));
        $this->assertSame(8, $row['status_id']);
        $this->assertSame('323456789', $row['personal_id']);

        // The next decision (another hold, then the answer) goes out in the same way and replaces the row of the day.
        $this->move($officer, 10, 2)->assertOk();
        $this->assertFalse($booking->fresh()->is_status_exported);
        app(BookingStatusSnapshotService::class)->export();
        $this->assertSame(10, $this->localStatusRows()[self::SERIAL]['status_id']);

        $this->move($officer, 17, 3)->assertOk();
        app(BookingStatusSnapshotService::class)->export();
        $this->assertSame(17, $this->localStatusRows()[self::SERIAL]['status_id']);
        $this->assertTrue($booking->fresh()->is_status_exported);
    }

    public function test_a_hold_is_sent_to_the_mobile_app_by_the_scheduled_export_too(): void
    {
        // Without the hatch the scheduled exportBookingStatusJson command writes the same file into the bucket.
        config(['services.digital-hatch.enabled' => false]);

        $booking = $this->booking();
        $officer = $this->controlOfficer();
        $this->move($officer, 9, 1)->assertOk();

        $this->assertTrue(app(BookingStatusExportService::class)->run()['status']['sent']);

        $files = Storage::disk('minio')->files('export-json/booking-status');
        $this->assertCount(1, $files);
        $bookings = json_decode(Storage::disk('minio')->get($files[0]), true)['bookings'];

        $this->assertSame([['serial_number' => self::SERIAL, 'status_id' => 9]], array_map(fn (array $row): array => ['serial_number' => $row['serial_number'], 'status_id' => $row['status_id']], $bookings));
        $this->assertSame('323456789', $bookings[0]['personal_id']);
        $this->assertTrue($booking->fresh()->is_status_exported);
        $this->assertSame(SyncFile::STATE_SENT, SyncFile::firstOrFail()->state);
    }

    //----------------------------- The model ------------------------------------//

    public function test_where_a_booking_is_and_what_a_move_leaves_parked(): void
    {
        $at = fn (int $status, ?int $parked): Booking => new Booking(['status_id' => $status, 'parked_status_id' => $parked]);

        // The position is the status it was put on hold from - for a hold only.
        $this->assertSame(1, $at(8, 1)->positionStatus()->value);
        $this->assertSame(8, $at(8, null)->positionStatus()->value, 'a hold with nowhere recorded is its own position');
        $this->assertSame(17, $at(17, 1)->positionStatus()->value, 'a value left on a status that is not a hold changes nothing');
        $this->assertSame(8, $at(8, 1)->holdStatus()->value);
        $this->assertNull($at(17, null)->holdStatus());

        // What a move leaves parked.
        $this->assertSame(1, $at(1, null)->parkedStatusFor(\App\Enums\StatusBookingEnum::PENDING_FILE));
        $this->assertSame(17, $at(17, null)->parkedStatusFor(\App\Enums\StatusBookingEnum::PENDING_PROCESSING));
        $this->assertSame(15, $at(15, null)->parkedStatusFor(\App\Enums\StatusBookingEnum::PENDING_APPROVED));
        $this->assertSame(14, $at(14, null)->parkedStatusFor(\App\Enums\StatusBookingEnum::PENDING_FILE));
        $this->assertSame(1, $at(8, 1)->parkedStatusFor(\App\Enums\StatusBookingEnum::PENDING_PROCESSING), 'hold to hold: stays');
        $this->assertNull($at(8, null)->parkedStatusFor(\App\Enums\StatusBookingEnum::PENDING_PROCESSING), 'an old hold stays unplaced');
        $this->assertNull($at(8, 1)->parkedStatusFor(\App\Enums\StatusBookingEnum::VICE_APPROVER_APPROVED), 'leaving the hold clears it');
        $this->assertNull($at(3, null)->parkedStatusFor(\App\Enums\StatusBookingEnum::PENDING_FILE), 'an approved booking is not parked anywhere');
        $this->assertNull($at(1, null)->parkedStatusFor(\App\Enums\StatusBookingEnum::APPROVED));
    }

    public function test_the_sql_of_the_position_matches_the_position_of_the_model(): void
    {
        $this->booking(8, ['serial_number' => 5801, 'parked_status_id' => 1]);
        $this->booking(8, ['serial_number' => 5802]);
        $this->booking(17, ['serial_number' => 5803, 'parked_status_id' => 1]);   // stale: ignored
        $this->booking(1, ['serial_number' => 5804]);

        $this->assertSame([5801, 5804], Booking::atPosition([1])->orderBy('serial_number')->pluck('serial_number')->map(fn ($serial): int => (int) $serial)->all());
        $this->assertSame([5802], Booking::atPosition([8])->pluck('serial_number')->map(fn ($serial): int => (int) $serial)->all());
        $this->assertSame([5803], Booking::atPosition([17])->pluck('serial_number')->map(fn ($serial): int => (int) $serial)->all());

        foreach (Booking::query()->get() as $booking) {
            $this->assertSame($booking->positionStatus()->value, (int) Booking::query()->whereKey($booking->id)->selectRaw(Booking::positionSql() . ' AS position')->value('position'));
        }
    }
}

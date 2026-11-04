<?php

namespace Tests\Feature;

use App\Jobs\SendHatchBookingStatusJob;
use App\Jobs\SendHatchUsersJob;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\DigitalHatchSentFile;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\BookingStatusExportService;
use App\Services\BookingStatusSnapshotService;
use App\Services\ImportBookingService;
use App\Services\UsersExportService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The r2b side of the digital hatch: two streams (the users file and the booking status file), each with its own command, job and service,
 * so each can be sent - and tested - alone.
 */
class DigitalHatchSendTest extends HatchTestCase
{
    /** A status file as exportBookingStatusJson:mysql leaves it in the bucket. */
    private function putStatusFile(string $name, array $bookings): void
    {
        $this->bucket()->put('export-json/booking-status/' . $name, json_encode(['bookings' => $bookings]));
    }

    private function decision(int $serial, int $statusId): array
    {
        return ['serial_number' => $serial, 'status_id' => $statusId, 'personal_id' => '123456789', 'updated_at' => '1791784345'];
    }

    /** @return array<int, int> */
    private function bookingTypeIds(): array
    {
        return BookingType::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function row(array $payload, string $personalId): ?array
    {
        return collect($payload)->firstWhere('personal_id', $personalId);
    }

    //----------------------------- The users file ------------------------------------//

    public function test_the_users_file_lists_each_person_with_the_booking_types_of_each_role(): void
    {
        $ids = $this->bookingTypeIds();
        [$first, $second] = $ids;

        // Two roles on different booking types; a deleted person and a person without an ID number never appear.
        User::where('personal_number', self::NO_ROLE)->update(['personal_id' => '323456789']);
        User::where('personal_number', self::NO_ROLE)->firstOrFail()->givePermissionTo(["vice_approver_{$first}", "vice_approver_{$second}", "user_{$second}"]);
        User::where('personal_number', self::APPROVER)->update(['personal_id' => '423456789', 'is_deleted' => true]);
        User::where('personal_number', self::ADMIN)->update(['personal_id' => null]);

        $payload = app(UsersExportService::class)->payload();

        $this->assertSame(['000000001', '123456789', '223456789', '323456789'], array_column($payload, 'personal_id'), 'ordered by ID number');

        // The super admin holds the role, not permissions: every booking type. The others hold a permission per type that has their role - the
        // barber type (5) has no vice approver, so the vice approver is listed for every type but that one.
        $this->assertSame([['role_id' => 1, 'booking_type_ids' => $ids]], $this->row($payload, '000000001')['booking_type_roles']);
        $this->assertSame([['role_id' => 4, 'booking_type_ids' => array_values(array_diff($ids, [5]))]], $this->row($payload, '123456789')['booking_type_roles']);
        $this->assertSame([['role_id' => 5, 'booking_type_ids' => $ids]], $this->row($payload, '223456789')['booking_type_roles']);
        $this->assertSame(
            [['role_id' => 4, 'booking_type_ids' => [$first, $second]], ['role_id' => 5, 'booking_type_ids' => [$second]]],
            $this->row($payload, '323456789')['booking_type_roles']
        );

        $viceApprover = $this->row($payload, '123456789');
        $this->assertSame(['personal_id', 'phone', 'unit_id', 'booking_type_roles', 'created_at', 'updated_at'], array_keys($viceApprover));
        $this->assertSame('0502222222', $viceApprover['phone']);
        $this->assertSame(1, $viceApprover['unit_id']);
        $this->assertTrue(ctype_digit($viceApprover['created_at']) && ctype_digit($viceApprover['updated_at']), 'unix seconds, as strings');
        $this->assertNull($this->row($payload, '223456789')['phone']);
    }

    public function test_a_role_given_in_the_web_reaches_the_users_file_with_a_newer_updated_at(): void
    {
        User::where('personal_number', self::NO_ROLE)->update(['personal_id' => '323456789']);
        User::where('personal_number', self::NO_ROLE)->toBase()->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->assertNull($this->row(app(UsersExportService::class)->payload(), '323456789'), 'no role yet, not in the file');

        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => User::where('personal_number', self::NO_ROLE)->value('uuid'),
                'role_assignments' => [['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => false]],
            ])
            ->assertOk();

        $row = $this->row(app(UsersExportService::class)->payload(), '323456789');

        $this->assertSame([['role_id' => 3, 'booking_type_ids' => [1]]], $row['booking_type_roles']);
        $this->assertGreaterThan(strtotime('2020-01-01'), (int) $row['updated_at'], 'a role change touches the person');
    }

    public function test_the_users_stream_writes_the_file_and_puts_it_on_the_ftp_once_per_content(): void
    {
        $name = $this->usersFileName();

        $result = app(UsersExportService::class)->export();

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['built']['written']);
        $this->assertSame($name, $result['file']);
        $this->assertSame(3, $result['marked'], 'the three people who went out are marked as sent');
        $this->assertStringContainsString(self::RED_TO_BLACK, $result['destination']);

        $json = $this->bucket()->get('export-json/users/' . $name);
        $this->assertSame($json, $this->ftp()->get(self::RED_TO_BLACK . $name), 'the same bytes in the bucket and on the FTP');
        $this->assertSame(['000000001', '123456789', '223456789'], array_column(json_decode($json, true)['users'], 'personal_id'));

        // Nobody is waiting any more: no new write, no new send.
        $again = app(UsersExportService::class)->export();
        $this->assertFalse($again['built']['written']);
        $this->assertSame('nothing_waiting', $again['built']['reason']);
        $this->assertSame(0, $again['marked']);
        $this->assertNull($again['file']);
        $this->assertSame(1, DigitalHatchSentFile::where('destination', 'ftp')->count());
        $this->assertSame(1, DigitalHatchSentFile::where('destination', 'bucket')->count(), 'what was written to the bucket is remembered too');

        // The admin takes a role away: the person waits again, the day's file is rewritten (the others stay in it) and goes again under the same name.
        $this->app['auth']->forgetGuards();
        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => User::where('personal_number', self::VICE_APPROVER)->value('uuid'),
                'role_assignments' => [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => false]],
            ])
            ->assertOk();

        $third = app(UsersExportService::class)->export();

        $this->assertTrue($third['built']['written']);
        $this->assertSame(1, $third['built']['users'], 'only the person whose role changed was waiting');
        $this->assertSame(3, $third['built']['in_file']);
        $this->assertSame(1, $third['marked']);
        $this->assertSame($name, $third['file']);
        $this->assertNotSame($json, $this->ftp()->get(self::RED_TO_BLACK . $name));
        $this->assertSame($this->bucket()->get('export-json/users/' . $name), $this->ftp()->get(self::RED_TO_BLACK . $name));
        $this->assertSame(2, DigitalHatchSentFile::where('destination', 'ftp')->count());
        $this->assertSame(2, DigitalHatchSentFile::where('destination', 'bucket')->count());

        // The same version went to both places: its name and sha256 are the same in the two rows.
        $this->assertSame(
            DigitalHatchSentFile::where('destination', 'ftp')->orderBy('id')->get(['file_name', 'sha256'])->toArray(),
            DigitalHatchSentFile::where('destination', 'bucket')->orderBy('id')->get(['file_name', 'sha256'])->toArray()
        );
        $this->assertSame(hash('sha256', $this->ftp()->get(self::RED_TO_BLACK . $name)), DigitalHatchSentFile::where('destination', 'ftp')->latest('id')->value('sha256'));
    }

    //----------------------------- The users file: the is_exported_role flag ------------------------------------//

    private function sent(string $personalNumber): bool
    {
        return (bool) User::where('personal_number', $personalNumber)->value('is_exported_role');
    }

    /** The admin (all the people of these tests are in their unit) grants or takes away approver roles. @param array<int, array<string, mixed>> $assignments */
    private function grantApprover(string $personalNumber, array $assignments): void
    {
        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => User::where('personal_number', $personalNumber)->value('uuid'),
                'role_assignments' => $assignments,
            ])
            ->assertOk();
    }

    /** @return array<int, array<string, mixed>> the people in the day's users file in the local folder, in file order */
    private function localUserRows(): array
    {
        $content = json_decode(file_get_contents($this->localDir . '/' . $this->usersFileName()), true);

        $this->assertSame(['users'], array_keys($content));

        return $content['users'];
    }

    public function test_a_new_person_waits_and_only_the_people_waiting_go_in_the_file(): void
    {
        $this->useLocalFolder();

        $this->assertFalse($this->sent(self::VICE_APPROVER), 'a new person waits to be sent');
        $this->assertArrayNotHasKey('is_exported_role', User::where('personal_number', self::VICE_APPROVER)->firstOrFail()->toArray(), 'bookkeeping, not shown');

        // The super admin went out already. The admin has no ID number (the mobile side knows people by it): never in the file, never marked.
        User::where('personal_number', self::SUPER_ADMIN)->toBase()->update(['is_exported_role' => true]);

        $result = app(UsersExportService::class)->export();

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['marked']);
        $this->assertSame(['123456789', '223456789'], array_column($this->localUserRows(), 'personal_id'));
        $this->assertTrue($this->sent(self::VICE_APPROVER));
        $this->assertTrue($this->sent(self::PLAIN_USER));
        $this->assertFalse($this->sent(self::ADMIN), 'not in the file, so still waiting');
        $this->assertFalse($this->sent(self::APPROVER));
    }

    public function test_a_role_given_or_taken_away_by_the_admin_or_the_super_admin_sends_the_person_again(): void
    {
        $this->useLocalFolder();
        app(UsersExportService::class)->export();

        $this->assertTrue($this->sent(self::VICE_APPROVER) && $this->sent(self::PLAIN_USER) && $this->sent(self::SUPER_ADMIN));

        // The admin makes the vice approver the approver of booking type 2 as well: only they wait again.
        $this->grantApprover(self::VICE_APPROVER, [['booking_type_id' => 2, 'is_approver' => true, 'is_vice_approver' => true]]);

        $this->assertFalse($this->sent(self::VICE_APPROVER));
        $this->assertTrue($this->sent(self::PLAIN_USER));
        $this->assertTrue($this->sent(self::SUPER_ADMIN));

        $first = app(UsersExportService::class)->export();

        $this->assertSame(1, $first['built']['users']);
        $this->assertSame(3, $first['built']['in_file'], 'the people sent earlier today stay in the day\'s file');
        $this->assertSame(1, $first['marked']);
        $this->assertSame(['000000001', '123456789', '223456789'], array_column($this->localUserRows(), 'personal_id'), 'ordered by ID number');

        $viceApprover = $this->row($this->localUserRows(), '123456789');
        $this->assertSame(3, $viceApprover['booking_type_roles'][0]['role_id']);
        $this->assertSame([2], $viceApprover['booking_type_roles'][0]['booking_type_ids']);
        $this->assertSame(4, $viceApprover['booking_type_roles'][1]['role_id']);

        // The super admin makes the plain user an admin of booking type 1.
        $this->app['auth']->forgetGuards();
        $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->postJson('/api/bookings-super-admin-management/assign-admin-role', [
                'uuid' => User::where('personal_number', self::PLAIN_USER)->value('uuid'),
                'role_assignments' => [['booking_type_id' => 1, 'is_admin' => true]],
            ])
            ->assertOk();

        $this->assertFalse($this->sent(self::PLAIN_USER));

        $second = app(UsersExportService::class)->export();

        $this->assertSame(1, $second['marked']);
        $this->assertSame(3, $second['built']['in_file']);

        $plainUser = $this->row($this->localUserRows(), '223456789');
        $this->assertSame([2, 5], array_column($plainUser['booking_type_roles'], 'role_id'));
        $this->assertSame([1], $plainUser['booking_type_roles'][0]['booking_type_ids']);
        $this->assertSame($viceApprover, $this->row($this->localUserRows(), '123456789'), 'the vice approver\'s row is the one sent before');
    }

    public function test_a_grant_that_changes_nothing_leaves_the_person_sent(): void
    {
        app(UsersExportService::class)->export();
        $updatedAt = User::where('personal_number', self::VICE_APPROVER)->toBase()->value('updated_at');

        // The vice approver holds exactly this already (vice approver of booking type 1, not its approver).
        $this->grantApprover(self::VICE_APPROVER, [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => true]]);

        $this->assertTrue($this->sent(self::VICE_APPROVER), 'nothing changed, nothing to send');
        $this->assertSame($updatedAt, User::where('personal_number', self::VICE_APPROVER)->toBase()->value('updated_at'));
        $this->assertSame('nothing_waiting', app(UsersExportService::class)->export()['built']['reason']);
    }

    public function test_when_the_send_fails_the_people_keep_waiting_and_go_with_the_next_run(): void
    {
        $this->useUnreachableFtp();

        $failed = app(UsersExportService::class)->export();

        $this->assertNotSame([], $failed['errors']);
        $this->assertSame(0, $failed['marked']);
        $this->assertTrue($failed['built']['written'], 'the file itself is in the bucket');
        $this->assertFalse($this->sent(self::VICE_APPROVER), 'not sent, so not marked');

        // The FTP is back: the same people are still waiting, and their file is in the bucket already.
        Storage::fake('digital-hatch');
        $ok = app(UsersExportService::class)->export();

        $this->assertSame([], $ok['errors']);
        $this->assertSame('unchanged', $ok['built']['reason']);
        $this->assertSame(3, $ok['marked']);
        $this->assertTrue($this->sent(self::VICE_APPROVER) && $this->sent(self::PLAIN_USER) && $this->sent(self::SUPER_ADMIN));
        $this->assertTrue($this->ftp()->exists(self::RED_TO_BLACK . $this->usersFileName()));
    }

    public function test_a_person_whose_roles_changed_while_the_file_was_built_stays_waiting(): void
    {
        $service = app(UsersExportService::class);

        $waiting = [];
        $service->run($waiting);
        $this->assertCount(3, $waiting);

        // A moment after the file was read, the admin changes the vice approver's roles.
        $this->travel(5)->seconds();
        $this->grantApprover(self::VICE_APPROVER, [['booking_type_id' => 2, 'is_approver' => true, 'is_vice_approver' => true]]);

        $this->assertSame(2, User::markRolesExported($waiting));
        $this->assertFalse($this->sent(self::VICE_APPROVER), 'their new roles are not in that file');
        $this->assertTrue($this->sent(self::PLAIN_USER));
        $this->assertTrue($this->sent(self::SUPER_ADMIN));

        $next = $service->export();

        $this->assertSame(1, $next['built']['users']);
        $this->assertSame(1, $next['marked']);
        $this->assertTrue($this->sent(self::VICE_APPROVER));
    }

    public function test_marking_does_not_touch_updated_at_and_a_person_without_one_is_marked_too(): void
    {
        User::where('personal_number', self::SUPER_ADMIN)->toBase()->update(['updated_at' => '2020-01-01 00:00:00']);
        User::where('personal_number', self::PLAIN_USER)->toBase()->update(['updated_at' => null]);

        $waiting = [];
        app(UsersExportService::class)->run($waiting);

        $this->assertContains('2020-01-01 00:00:00', $waiting);
        $this->assertContains(null, $waiting);

        $this->assertSame(3, User::markRolesExported($waiting));
        $this->assertSame('2020-01-01 00:00:00', User::where('personal_number', self::SUPER_ADMIN)->toBase()->value('updated_at'));
        $this->assertNull(User::where('personal_number', self::PLAIN_USER)->toBase()->value('updated_at'));
        $this->assertSame(0, User::markRolesExported($waiting), 'a person is marked once');
    }

    public function test_the_users_command_says_how_many_people_it_marked(): void
    {
        $this->artisan('digital-hatch:send-users', ['--sync' => true])
            ->expectsOutputToContain("Users file written (3 waiting, 3 in the day's file)")
            ->expectsOutputToContain('Marked as exported (is_exported_role = 1): 3 users.')
            ->assertExitCode(0);

        $this->artisan('digital-hatch:send-users', ['--sync' => true])
            ->expectsOutputToContain('no person is waiting')
            ->expectsOutputToContain('Marked as exported (is_exported_role = 1): 0 users.')
            ->assertExitCode(0);
    }

    //----------------------------- The booking status file ------------------------------------//

    public function test_the_booking_status_stream_sends_only_the_newest_file_and_again_when_it_grows(): void
    {
        $old = 'SrvOnClick_r2b_booking_status_2026-11-01.json';
        $new = 'SrvOnClick_r2b_booking_status_2026-11-02.json';

        $this->putStatusFile($old, [$this->decision(1, 3)]);
        $this->putStatusFile($new, [$this->decision(2, 4)]);

        $result = app(BookingStatusSnapshotService::class)->export();

        $this->assertSame($new, $result['file']);
        $this->assertTrue($this->ftp()->exists(self::RED_TO_BLACK . $new));
        $this->assertFalse($this->ftp()->exists(self::RED_TO_BLACK . $old), 'a cumulative snapshot: the newest holds the older');

        $this->assertNull(app(BookingStatusSnapshotService::class)->export()['file'], 'same content, not sent again');

        $this->putStatusFile($new, [$this->decision(2, 4), $this->decision(3, 5)]);

        $this->assertSame($new, app(BookingStatusSnapshotService::class)->export()['file']);
        $this->assertSame($this->bucket()->get('export-json/booking-status/' . $new), $this->ftp()->get(self::RED_TO_BLACK . $new));
    }

    //----------------------------- The booking status file: the is_status_exported flag ------------------------------------//

    /** Two bookings as the mobile app sent them: is_status_exported = 1, it has them. */
    private function importBookings(): void
    {
        $this->putBookingFile([$this->foodBooking(5201), $this->foodBooking(5202)]);
        app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    /** A person decides here (changeStatus): the status moves and the booking waits to be sent. */
    private function decide(int $serial, int $version = 1): void
    {
        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::VICE_APPROVER)
            ->postJson("/api/bookings-approver-management/bookings/{$serial}/status", ['status_id' => 15, 'version' => $version])
            ->assertOk();
    }

    private function exported(int $serial): bool
    {
        return (bool) Booking::where('serial_number', $serial)->value('is_status_exported');
    }

    /** @return array<int, array<string, mixed>> the rows of a status file in the local folder, by serial number */
    private function localStatusRows(): array
    {
        $content = json_decode(file_get_contents($this->localDir . '/' . $this->statusFileName()), true);

        $this->assertSame(['bookings'], array_keys($content));

        return collect($content['bookings'])->keyBy('serial_number')->all();
    }

    public function test_a_decision_made_here_is_exported_once_in_the_shape_of_the_status_file_and_the_flag_follows_it(): void
    {
        $this->useLocalFolder();
        $this->importBookings();

        $this->assertTrue($this->exported(5201), 'a booking the mobile app sent has nothing to send');

        // changeStatus: the status and the flag change together.
        $this->decide(5201);
        $this->assertFalse($this->exported(5201), 'the decision waits to be sent');
        $this->assertSame(15, Booking::where('serial_number', 5201)->value('status_id'));

        $result = app(BookingStatusSnapshotService::class)->export();

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['built']['written']);
        $this->assertSame($this->statusFileName(), $result['file']);
        $this->assertSame(1, $result['marked']);
        $this->assertTrue($this->exported(5201), 'sent, so it is marked');
        $this->assertTrue($this->exported(5202), 'a booking nobody decided on never was in the file');

        $rows = $this->localStatusRows();
        $this->assertSame([5201], array_keys($rows));
        $this->assertSame(['serial_number', 'status_id', 'personal_id', 'updated_at'], array_keys($rows[5201]));
        $this->assertSame(15, $rows[5201]['status_id']);
        $this->assertSame('123456789', $rows[5201]['personal_id'], 'the ID number of whoever decided');
        $this->assertTrue(ctype_digit($rows[5201]['updated_at']), 'unix seconds, as a string');

        // Nothing is waiting now: nothing is written or sent.
        $again = app(BookingStatusSnapshotService::class)->export();
        $this->assertSame('nothing_waiting', $again['built']['reason']);
        $this->assertNull($again['file']);
        $this->assertSame(0, $again['marked']);
    }

    public function test_the_days_file_keeps_the_earlier_decisions_and_a_booking_decided_again_replaces_its_row(): void
    {
        $this->useLocalFolder();
        $this->importBookings();

        $this->decide(5201);
        app(BookingStatusSnapshotService::class)->export();

        $this->decide(5202);
        $second = app(BookingStatusSnapshotService::class)->export();

        $this->assertSame(2, $second['built']['in_file'], 'the earlier decision is still in the file of the day');
        $this->assertSame(1, $second['built']['bookings']);
        $this->assertSame([5201, 5202], array_keys($this->localStatusRows()));

        // Decided again (a later status): the flag is back to waiting, and the booking's row is replaced, not repeated.
        Booking::where('serial_number', 5201)->update(['status_id' => 16, 'version' => 2, 'is_status_exported' => false, 'status_changed_at' => now()]);
        $third = app(BookingStatusSnapshotService::class)->export();

        $rows = $this->localStatusRows();
        $this->assertSame([5201, 5202], array_keys($rows));
        $this->assertSame(16, $rows[5201]['status_id']);
        $this->assertSame(15, $rows[5202]['status_id']);
        $this->assertSame(1, $third['marked']);
        $this->assertTrue($this->exported(5201));
    }

    public function test_a_booking_with_an_open_sync_conflict_is_held_back_and_keeps_waiting(): void
    {
        $this->useLocalFolder();
        $this->importBookings();

        $this->decide(5201);
        SyncConflict::create([
            'booking_id' => Booking::where('serial_number', 5201)->value('id'),
            'base_status_id' => 1, 'internal_status_id' => 15, 'external_status_id' => 6, 'state' => SyncConflict::STATE_OPEN,
        ]);

        $result = app(BookingStatusSnapshotService::class)->export();

        $this->assertSame('nothing_waiting', $result['built']['reason']);
        $this->assertFalse($this->exported(5201), 'it waits until a person settles the conflict');
        $this->assertFileDoesNotExist($this->localDir . '/' . $this->statusFileName());
    }

    public function test_when_the_send_fails_the_bookings_keep_waiting_and_go_with_the_next_run(): void
    {
        $this->importBookings();
        $this->decide(5201);
        $this->useUnreachableFtp();

        $failed = app(BookingStatusSnapshotService::class)->export();

        $this->assertNotSame([], $failed['errors']);
        $this->assertSame(0, $failed['marked']);
        $this->assertFalse($this->exported(5201), 'not sent, so not marked');

        // The FTP is back: the file of the day is still in the bucket and the booking still waits.
        Storage::fake('digital-hatch');
        $ok = app(BookingStatusSnapshotService::class)->export();

        $this->assertSame([], $ok['errors']);
        $this->assertSame(1, $ok['marked']);
        $this->assertTrue($this->exported(5201));
        $this->assertTrue($this->ftp()->exists(self::RED_TO_BLACK . $this->statusFileName()));
    }

    public function test_the_bucket_export_leaves_the_status_to_the_hatch_while_it_is_on(): void
    {
        $this->importBookings();
        $this->decide(5201);

        $this->assertSame(['sent' => false, 'reason' => 'digital_hatch'], app(BookingStatusExportService::class)->exportStatus());
        $this->assertFalse($this->exported(5201), 'only the hatch clears the flag');
        $this->assertSame([], $this->bucket()->files('export-json/booking-status'));
    }

    //----------------------------- Each stream alone ------------------------------------//

    public function test_each_stream_is_sent_on_its_own(): void
    {
        $this->putStatusFile($this->statusFileName(), [$this->decision(2, 4)]);

        app(UsersExportService::class)->export();

        $this->assertSame([self::RED_TO_BLACK . $this->usersFileName()], $this->ftp()->allFiles(), 'the users stream does not touch the status file');

        app(BookingStatusSnapshotService::class)->export();

        $this->assertEqualsCanonicalizing(
            [self::RED_TO_BLACK . $this->usersFileName(), self::RED_TO_BLACK . $this->statusFileName()],
            $this->ftp()->allFiles()
        );
    }

    public function test_for_local_testing_the_files_are_written_into_a_folder_and_the_ftp_is_not_touched(): void
    {
        $this->useLocalFolder();
        $this->putStatusFile($this->statusFileName(), [$this->decision(2, 4)]);

        $users = app(UsersExportService::class)->export();
        $status = app(BookingStatusSnapshotService::class)->export();

        $this->assertSame([], [...$users['errors'], ...$status['errors']]);
        $this->assertStringContainsString($this->localDir, $users['destination']);
        $this->assertSame($this->bucket()->get('export-json/users/' . $this->usersFileName()), file_get_contents($this->localDir . '/' . $this->usersFileName()));
        $this->assertSame($this->bucket()->get('export-json/booking-status/' . $this->statusFileName()), file_get_contents($this->localDir . '/' . $this->statusFileName()));
        $this->assertSame([], $this->ftp()->allFiles());
    }

    public function test_force_sends_the_same_content_again_for_example_after_the_file_was_deleted_at_the_other_end(): void
    {
        $this->useLocalFolder();
        $file = $this->localDir . '/' . $this->usersFileName();

        app(UsersExportService::class)->export();
        unlink($file);

        $this->assertNull(app(UsersExportService::class)->export()['file'], 'remembered as sent');
        $this->assertFileDoesNotExist($file);

        $this->assertSame($this->usersFileName(), app(UsersExportService::class)->export(force: true)['file']);
        $this->assertFileExists($file);
        $this->assertSame(1, DigitalHatchSentFile::where('destination', 'local')->count(), 'a version is remembered once per place it went to');
        $this->assertSame(0, DigitalHatchSentFile::where('destination', 'ftp')->count(), 'the local folder took the FTP\'s place');
    }

    //----------------------------- Commands and jobs ------------------------------------//

    public function test_each_command_queues_only_its_own_job_and_sends_nothing_until_it_runs(): void
    {
        Queue::fake();

        $this->artisan('digital-hatch:send-users')->expectsOutputToContain('SendHatchUsersJob queued')->assertExitCode(0);

        Queue::assertPushed(SendHatchUsersJob::class, 1);
        Queue::assertNotPushed(SendHatchBookingStatusJob::class);

        $this->artisan('digital-hatch:send-booking-status')->expectsOutputToContain('SendHatchBookingStatusJob queued')->assertExitCode(0);

        Queue::assertPushed(SendHatchBookingStatusJob::class, 1);
        $this->assertSame([], $this->ftp()->allFiles());
        $this->assertSame([], $this->bucket()->allFiles('export-json'), 'no file is built until a job runs');
    }

    public function test_force_is_passed_on_to_the_queued_job(): void
    {
        Queue::fake();

        $this->artisan('digital-hatch:send-users', ['--force' => true])->assertExitCode(0);

        Queue::assertPushed(SendHatchUsersJob::class, fn (SendHatchUsersJob $job): bool => $job->force === true);
    }

    public function test_the_jobs_do_the_work_of_their_stream(): void
    {
        $this->putStatusFile($this->statusFileName(), [$this->decision(2, 4)]);

        SendHatchUsersJob::dispatchSync();
        $this->assertSame([self::RED_TO_BLACK . $this->usersFileName()], $this->ftp()->allFiles());

        SendHatchBookingStatusJob::dispatchSync();
        $this->assertTrue($this->ftp()->exists(self::RED_TO_BLACK . $this->statusFileName()));
    }

    public function test_with_sync_each_command_sends_now_and_says_what_it_did(): void
    {
        $this->putStatusFile($this->statusFileName(), [$this->decision(2, 4)]);

        $this->artisan('digital-hatch:send-users', ['--sync' => true])
            ->expectsOutputToContain('Users file written')
            ->expectsOutputToContain('Sent: ' . $this->usersFileName())
            ->assertExitCode(0);
        $this->assertSame([self::RED_TO_BLACK . $this->usersFileName()], $this->ftp()->allFiles());

        $this->artisan('digital-hatch:send-booking-status', ['--sync' => true])
            ->expectsOutputToContain('no booking is waiting')
            ->expectsOutputToContain('Sent: ' . $this->statusFileName())
            ->assertExitCode(0);

        $this->artisan('digital-hatch:send-users', ['--sync' => true])->expectsOutputToContain('Nothing new to send')->assertExitCode(0);
        $this->artisan('digital-hatch:send-booking-status', ['--sync' => true])->expectsOutputToContain('Nothing new to send')->assertExitCode(0);

        $this->artisan('digital-hatch:send-users', ['--sync' => true, '--force' => true])->expectsOutputToContain('Sent: ' . $this->usersFileName())->assertExitCode(0);
    }

    public function test_nothing_is_queued_or_sent_while_the_hatch_is_disabled(): void
    {
        config(['services.digital-hatch.enabled' => false]);
        Queue::fake();
        $this->putStatusFile($this->statusFileName(), [$this->decision(2, 4)]);

        $this->assertSame(['enabled' => false], app(UsersExportService::class)->export());
        $this->assertSame(['enabled' => false], app(BookingStatusSnapshotService::class)->export());

        $this->artisan('digital-hatch:send-users')->expectsOutputToContain('disabled')->assertExitCode(0);
        $this->artisan('digital-hatch:send-booking-status', ['--sync' => true])->expectsOutputToContain('disabled')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame([], $this->ftp()->allFiles());
        $this->assertSame([], $this->bucket()->files('export-json/users'));
    }

    public function test_an_unreachable_ftp_fails_the_run_and_the_job_instead_of_looking_like_a_success(): void
    {
        $this->putStatusFile($this->statusFileName(), [$this->decision(2, 4)]);
        $this->useUnreachableFtp();

        $this->assertNotSame([], app(UsersExportService::class)->export()['errors']);
        $this->assertNotSame([], app(BookingStatusSnapshotService::class)->export()['errors']);
        $this->assertSame(0, DigitalHatchSentFile::whereIn('destination', ['ftp', 'local'])->count(), 'a file that did not go is not remembered as sent');

        $this->artisan('digital-hatch:send-users', ['--sync' => true])->assertExitCode(1);
        $this->artisan('digital-hatch:send-booking-status', ['--sync' => true])->assertExitCode(1);

        // Thrown, so the queue tries again after the back-off.
        $this->expectException(\RuntimeException::class);
        SendHatchUsersJob::dispatchSync();
    }
}

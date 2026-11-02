<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingType;
use App\Models\DigitalHatchFetchedFile;
use App\Models\DigitalHatchSentFile;
use App\Models\File;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\DigitalHatchService;
use App\Services\ImportBookingService;
use App\Services\UsersExportService;
use Illuminate\Support\Facades\Storage;

/**
 * The digital hatch (FTP) between this system and the mobile side. The bucket is a folder in memory (ImportJsonTestCase) and so is the FTP
 * (Storage::fake on the "digital-hatch" disk): no test here reaches a real server.
 */
class DigitalHatchTest extends ImportJsonTestCase
{
    private const RED_TO_BLACK = 'R2B/Tikshoret-R2B-Prod/';
    private const BLACK_TO_RED = 'Tikshoret-B2R-Prod/';
    private const ATTACHMENTS = 'DigitalFiles-B2R-Prod/';

    private const BOOKINGS_ON_FTP = 'SrvOnClick_b2r_export_bookings_2026-11-02_08-54-11.json';
    private const LOGS_ON_FTP = 'SrvOnClick_b2r_application_logs_2026-11-02_08-54-17.json';

    private const PDF = 'SrvOnClickFiles_6adf010810404_j0iNc8FqHf';
    private const DOC = 'SrvOnClickFiles_0b3f8e2a-5c1d-4e7f-9a62-3d8c41f7b9e0';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('digital-hatch');

        config([
            'services.digital-hatch.enabled' => true,
            'services.digital-hatch.red_to_black_ftp_path' => '/' . self::RED_TO_BLACK,
            'services.digital-hatch.black_to_red_ftp_path' => '/' . self::BLACK_TO_RED,
            'services.digital-hatch.black_to_red_ftp_attachments_path' => '/' . self::ATTACHMENTS,
        ]);

        // The mobile app knows the people by their ID number.
        User::where('personal_number', self::SUPER_ADMIN)->update(['personal_id' => '000000001', 'phone' => '0501111111']);
        User::where('personal_number', self::VICE_APPROVER)->update(['personal_id' => '123456789', 'phone' => '0502222222']);
        User::where('personal_number', self::PLAIN_USER)->update(['personal_id' => '223456789']);
    }

    private function ftp(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('digital-hatch');
    }

    private function bucket(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('minio');
    }

    private function today(): string
    {
        return now(config('app.export_json.timezone'))->format('Y-m-d');
    }

    private function usersFileName(): string
    {
        return 'SrvOnClick_r2b_users_' . $this->today() . '.json';
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

        // The super admin holds the role, not permissions: every booking type.
        $this->assertSame([['role_id' => 1, 'booking_type_ids' => $ids]], $this->row($payload, '000000001')['booking_type_roles']);
        $this->assertSame([['role_id' => 4, 'booking_type_ids' => $ids]], $this->row($payload, '123456789')['booking_type_roles']);
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

    //----------------------------- Sending (r2b) ------------------------------------//

    public function test_export_writes_the_users_file_and_puts_it_on_the_ftp_once_per_content(): void
    {
        $name = $this->usersFileName();

        $result = app(DigitalHatchService::class)->export();

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['users']['written']);
        $this->assertSame([$name], array_values($result['files']));
        $this->assertSame('users', array_key_first($result['files']));

        $json = $this->bucket()->get('export-json/users/' . $name);
        $this->assertSame($json, $this->ftp()->get(self::RED_TO_BLACK . $name), 'the same bytes in the bucket and on the FTP');
        $this->assertSame(['000000001', '123456789', '223456789'], array_column(json_decode($json, true)['users'], 'personal_id'));

        // Nothing changed: no new write, no new send.
        $again = app(DigitalHatchService::class)->export();
        $this->assertFalse($again['users']['written']);
        $this->assertSame('unchanged', $again['users']['reason']);
        $this->assertSame([], $again['files']);
        $this->assertSame(1, DigitalHatchSentFile::count());

        // A role is taken away: the day's file is rewritten and goes again under the same name.
        User::where('personal_number', self::PLAIN_USER)->firstOrFail()->revokePermissionTo('user_1');
        $third = app(DigitalHatchService::class)->export();

        $this->assertTrue($third['users']['written']);
        $this->assertSame([$name], array_values($third['files']));
        $this->assertNotSame($json, $this->ftp()->get(self::RED_TO_BLACK . $name));
        $this->assertSame($this->bucket()->get('export-json/users/' . $name), $this->ftp()->get(self::RED_TO_BLACK . $name));
        $this->assertSame(2, DigitalHatchSentFile::count());
    }

    public function test_export_sends_only_the_newest_booking_status_file_and_again_when_it_grows(): void
    {
        $directory = 'export-json/booking-status/';
        $old = 'SrvOnClick_r2b_booking_status_2026-11-01.json';
        $new = 'SrvOnClick_r2b_booking_status_2026-11-02.json';

        $this->bucket()->put($directory . $old, json_encode(['bookings' => [['serial_number' => 1, 'status_id' => 3, 'personal_id' => '123456789', 'updated_at' => '1791784344']]]));
        $this->bucket()->put($directory . $new, json_encode(['bookings' => [['serial_number' => 2, 'status_id' => 4, 'personal_id' => '123456789', 'updated_at' => '1791784345']]]));

        $result = app(DigitalHatchService::class)->export();

        $this->assertSame($new, $result['files']['booking_status']);
        $this->assertTrue($this->ftp()->exists(self::RED_TO_BLACK . $new));
        $this->assertFalse($this->ftp()->exists(self::RED_TO_BLACK . $old), 'a cumulative snapshot: the newest holds the older');

        $this->assertArrayNotHasKey('booking_status', app(DigitalHatchService::class)->export()['files'], 'same content, not sent again');

        $grown = json_encode(['bookings' => [['serial_number' => 2, 'status_id' => 4, 'personal_id' => '123456789', 'updated_at' => '1791784345'], ['serial_number' => 3, 'status_id' => 5, 'personal_id' => '123456789', 'updated_at' => '1791784350']]]);
        $this->bucket()->put($directory . $new, $grown);

        $this->assertSame($new, app(DigitalHatchService::class)->export()['files']['booking_status']);
        $this->assertSame($grown, $this->ftp()->get(self::RED_TO_BLACK . $new));
    }

    //----------------------------- Fetching (b2r) ------------------------------------//

    public function test_fetch_lands_the_new_b2r_files_where_the_importers_read_them_and_only_once(): void
    {
        $bookings = json_encode(['bookings' => [$this->foodBooking(5101)]]);
        $logs = json_encode(['application_logs' => []]);

        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, $bookings);
        $this->ftp()->put(self::BLACK_TO_RED . self::LOGS_ON_FTP, $logs);
        // Not for this system: our own r2b file, another stream, another prefix, another type.
        $this->ftp()->put(self::BLACK_TO_RED . 'SrvOnClick_r2b_users_2026-11-02.json', '{"users":[]}');
        $this->ftp()->put(self::BLACK_TO_RED . 'SrvOnClick_b2r_something_else_2026-11-02.json', '{"x":[]}');
        $this->ftp()->put(self::BLACK_TO_RED . 'srvonclick_b2r_export_bookings_2026-11-02.json', $bookings);
        $this->ftp()->put(self::BLACK_TO_RED . 'SrvOnClick_b2r_export_bookings_2026-11-02_10-00-00.txt', $bookings);

        $result = app(DigitalHatchService::class)->fetch();

        $this->assertSame([], $result['errors']);
        $this->assertEquals(['export_bookings' => [self::BOOKINGS_ON_FTP], 'application_logs' => [self::LOGS_ON_FTP]], $result['files'], 'the order of the streams does not matter');
        $this->assertSame($bookings, $this->bucket()->get('import-json/booking-data/' . self::BOOKINGS_ON_FTP));
        $this->assertSame($logs, $this->bucket()->get('import-json/application-log/' . self::LOGS_ON_FTP));
        $this->assertCount(1, $this->bucket()->files('import-json/booking-data'));
        $this->assertCount(1, $this->bucket()->files('import-json/application-log'));
        $this->assertEqualsCanonicalizing([self::BOOKINGS_ON_FTP, self::LOGS_ON_FTP], DigitalHatchFetchedFile::pluck('file_name')->all());
        $this->assertTrue($this->ftp()->exists(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP), 'the FTP is never cleaned');

        // The bucket file is read by the existing import as if the mobile app had written it there.
        app(ImportBookingService::class)->importFromBucket(sync: true);
        $this->assertTrue(Booking::where('serial_number', 5101)->exists());
        $this->assertSame([], $this->bucket()->files('import-json/booking-data'), 'the import moved it to loaded-json');

        // Fetched once: the import moved the file away and nothing brings it back.
        $again = app(DigitalHatchService::class)->fetch();
        $this->assertSame([], $again['files']);
        $this->assertSame([], $this->bucket()->files('import-json/booking-data'));
    }

    public function test_a_file_still_being_uploaded_waits_for_the_next_run(): void
    {
        $partial = 'SrvOnClick_b2r_export_bookings_2026-11-02_09-00-00.json';
        $this->ftp()->put(self::BLACK_TO_RED . $partial, '{"bookings": [');

        $first = app(DigitalHatchService::class)->fetch();

        $this->assertSame([], $first['files']);
        $this->assertStringContainsString($partial, $first['errors'][0]);
        $this->assertSame([], $this->bucket()->files('import-json/booking-data'));
        $this->assertSame(0, DigitalHatchFetchedFile::count(), 'not marked, so it is tried again');

        $this->ftp()->put(self::BLACK_TO_RED . $partial, json_encode(['bookings' => []]));
        $second = app(DigitalHatchService::class)->fetch();

        $this->assertSame([], $second['errors']);
        $this->assertSame(['export_bookings' => [$partial]], $second['files']);
        $this->assertTrue($this->bucket()->exists('import-json/booking-data/' . $partial));
    }

    public function test_fetch_attachments_takes_only_the_files_the_bookings_can_name_and_serve(): void
    {
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', '%PDF-1.4 a');
        $this->ftp()->put(self::ATTACHMENTS . self::DOC . '.docx', 'PK docx');
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.exe', 'MZ');
        $this->ftp()->put(self::ATTACHMENTS . 'SrvOnClickFiles_with space.pdf', 'x');
        $this->ftp()->put(self::ATTACHMENTS . 'Other_abc.pdf', 'x');
        $this->ftp()->put(self::ATTACHMENTS . 'srvonclickfiles_abc.pdf', 'x');

        $result = app(DigitalHatchService::class)->fetchAttachments();

        $this->assertSame([], $result['errors']);
        $this->assertSame([self::DOC . '.docx', self::PDF . '.pdf'], $result['files']);
        $this->assertSame('%PDF-1.4 a', $this->bucket()->get('import-files/' . self::PDF . '.pdf'));
        $this->assertSame('PK docx', $this->bucket()->get('import-files/' . self::DOC . '.docx'));
        $this->assertEqualsCanonicalizing(['import-files/' . self::PDF . '.pdf', 'import-files/' . self::DOC . '.docx'], $this->bucket()->files('import-files'));

        // AttachmentService finds what the booking json named without an extension.
        $file = File::create(['name' => self::PDF, 'display_name' => self::PDF, 'path' => 'import-files/' . self::PDF, 'file_name' => self::PDF]);
        $this->assertSame('import-files/' . self::PDF . '.pdf', app(AttachmentService::class)->resolve($file));

        $this->assertSame([], app(DigitalHatchService::class)->fetchAttachments()['files'], 'fetched once');
    }

    //----------------------------- Switches and failures ------------------------------------//

    public function test_nothing_happens_while_the_hatch_is_disabled(): void
    {
        config(['services.digital-hatch.enabled' => false]);

        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, json_encode(['bookings' => []]));
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', 'x');

        $this->assertSame(['enabled' => false], app(DigitalHatchService::class)->export());
        $this->assertSame(['enabled' => false], app(DigitalHatchService::class)->fetch());
        $this->assertSame(['enabled' => false], app(DigitalHatchService::class)->fetchAttachments());

        $this->assertSame([], $this->bucket()->allFiles());
        $this->assertSame([], $this->ftp()->files(self::RED_TO_BLACK));

        $this->artisan('digital-hatch:send')->expectsOutputToContain('disabled')->assertExitCode(0);
        $this->artisan('digital-hatch:fetch')->expectsOutputToContain('disabled')->assertExitCode(0);
        $this->artisan('digital-hatch:fetch-attachments')->expectsOutputToContain('disabled')->assertExitCode(0);
    }

    public function test_the_commands_report_what_they_moved(): void
    {
        $this->ftp()->put(self::BLACK_TO_RED . self::LOGS_ON_FTP, json_encode(['application_logs' => []]));
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', 'x');

        $this->artisan('digital-hatch:send')->expectsOutputToContain('Sent users: ' . $this->usersFileName())->assertExitCode(0);
        $this->artisan('digital-hatch:fetch')->expectsOutputToContain('Fetched application_logs: ' . self::LOGS_ON_FTP)->assertExitCode(0);
        $this->artisan('digital-hatch:fetch-attachments')->expectsOutputToContain('Fetched ' . self::PDF . '.pdf')->assertExitCode(0);

        $this->artisan('digital-hatch:send')->expectsOutputToContain('Nothing new to send.')->assertExitCode(0);
        $this->artisan('digital-hatch:fetch')->expectsOutputToContain('No new file on the FTP.')->assertExitCode(0);
        $this->artisan('digital-hatch:fetch-attachments')->expectsOutputToContain('No new attachment on the FTP.')->assertExitCode(0);
    }

    public function test_an_unreachable_ftp_fails_the_run_instead_of_looking_like_an_empty_folder(): void
    {
        // Nothing listens on port 1: the connection is refused at once.
        config(['filesystems.disks.digital-hatch' => [
            'driver' => 'ftp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p',
            'timeout' => 2, 'throw' => true, 'report' => false,
        ]]);
        Storage::forgetDisk('digital-hatch');

        $this->assertNotSame([], app(DigitalHatchService::class)->fetch()['errors']);
        $this->assertNotSame([], app(DigitalHatchService::class)->fetchAttachments()['errors']);
        $this->assertNotSame([], app(DigitalHatchService::class)->export()['errors']);
        $this->assertSame(0, DigitalHatchSentFile::count(), 'a file that did not go is not remembered as sent');

        $this->artisan('digital-hatch:fetch')->assertExitCode(1);
    }
}

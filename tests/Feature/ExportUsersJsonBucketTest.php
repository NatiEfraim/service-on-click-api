<?php

namespace Tests\Feature;

use App\Models\DigitalHatchSentFile;
use App\Models\User;
use App\Services\StorageService;
use App\Services\UsersExportService;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

/**
 * The second way out of the users file: exportUsersJson:bucket writes the day's file to the bucket alone, for when the digital hatch is off.
 * It follows the same rule as digital-hatch:send-users - only the people with users.is_exported_role = 0 go out, and they are marked 1 once
 * the file is out - and the two share one flag, so only the one the HATCH_ENABLED switch picks works.
 */
class ExportUsersJsonBucketTest extends HatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Whatever the .env says about the bucket export.
        config(['app.export_json.enabled' => true]);
    }

    private function hatchOff(): void
    {
        config(['services.digital-hatch.enabled' => false]);
    }

    private function hatchOn(): void
    {
        config(['services.digital-hatch.enabled' => true]);
    }

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

    /** @return array<int, array<string, mixed>> the people in the day's users file in the bucket, in file order */
    private function bucketRows(): array
    {
        $content = json_decode($this->bucket()->get('export-json/users/' . $this->usersFileName()), true);

        $this->assertSame(['users'], array_keys($content));

        return $content['users'];
    }

    /** @return array<int, string> */
    private function bucketPeople(): array
    {
        return array_column($this->bucketRows(), 'personal_id');
    }

    public function test_with_the_hatch_off_the_waiting_people_go_in_the_bucket_file_and_are_marked(): void
    {
        $this->hatchOff();

        $result = app(UsersExportService::class)->exportToBucket();

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['built']['written']);
        $this->assertSame(3, $result['marked']);
        $this->assertSame(['000000001', '123456789', '223456789'], $this->bucketPeople());
        $this->assertTrue($this->sent(self::SUPER_ADMIN) && $this->sent(self::VICE_APPROVER) && $this->sent(self::PLAIN_USER));
        $this->assertFalse($this->sent(self::ADMIN), 'no ID number: never in the file, so still waiting');

        // The bucket is the only place it goes to.
        $this->assertSame([], $this->ftp()->allFiles());
        $this->assertSame(1, DigitalHatchSentFile::where('destination', 'bucket')->count(), 'what was written to the bucket is remembered');
        $this->assertSame(0, DigitalHatchSentFile::whereIn('destination', ['ftp', 'local'])->count());

        // Nobody is waiting any more: nothing is written.
        $again = app(UsersExportService::class)->exportToBucket();

        $this->assertFalse($again['built']['written']);
        $this->assertSame('nothing_waiting', $again['built']['reason']);
        $this->assertSame(0, $again['marked']);
    }

    public function test_only_the_people_with_the_flag_at_zero_go_out_by_the_bucket_too(): void
    {
        $this->hatchOff();
        User::where('personal_number', self::SUPER_ADMIN)->toBase()->update(['is_exported_role' => true]);

        $result = app(UsersExportService::class)->exportToBucket();

        $this->assertSame(2, $result['marked']);
        $this->assertSame(['123456789', '223456789'], $this->bucketPeople());
        $this->assertTrue($this->sent(self::SUPER_ADMIN), 'sent before, still sent');
    }

    public function test_both_ways_write_the_same_file_for_the_same_people(): void
    {
        $this->hatchOff();
        app(UsersExportService::class)->exportToBucket();
        $viaBucket = $this->bucket()->get('export-json/users/' . $this->usersFileName());

        // The same people wait again and the file is gone; this time the hatch sends them.
        User::query()->toBase()->update(['is_exported_role' => false]);
        $this->bucket()->delete('export-json/users/' . $this->usersFileName());
        $this->hatchOn();

        app(UsersExportService::class)->export();

        $this->assertSame($viaBucket, $this->bucket()->get('export-json/users/' . $this->usersFileName()), 'same rule, same file');
        $this->assertSame($viaBucket, $this->ftp()->get(self::RED_TO_BLACK . $this->usersFileName()));
    }

    public function test_with_the_hatch_on_the_bucket_export_steps_aside_and_the_people_keep_waiting(): void
    {
        $this->hatchOn();

        $this->assertSame(['skipped' => 'digital_hatch'], app(UsersExportService::class)->exportToBucket());

        $this->assertSame([], $this->bucket()->files('export-json/users'));
        $this->assertFalse($this->sent(self::VICE_APPROVER), 'only the hatch clears the flag');
    }

    public function test_the_export_switch_stops_it_and_the_people_keep_waiting(): void
    {
        $this->hatchOff();
        config(['app.export_json.enabled' => false]);

        $this->assertSame(['skipped' => 'export_json_disabled'], app(UsersExportService::class)->exportToBucket());

        $this->assertSame([], $this->bucket()->files('export-json/users'));
        $this->assertFalse($this->sent(self::VICE_APPROVER));
    }

    public function test_a_role_change_makes_the_person_wait_again_and_the_days_file_keeps_the_others(): void
    {
        $this->hatchOff();
        app(UsersExportService::class)->exportToBucket();

        // The admin makes the vice approver the approver of booking type 2 as well: only they wait again.
        $this->grantApprover(self::VICE_APPROVER, [['booking_type_id' => 2, 'is_approver' => true, 'is_vice_approver' => true]]);

        $this->assertFalse($this->sent(self::VICE_APPROVER));
        $this->assertTrue($this->sent(self::PLAIN_USER));

        $second = app(UsersExportService::class)->exportToBucket();

        $this->assertSame(1, $second['built']['users']);
        $this->assertSame(3, $second['built']['in_file'], 'the people sent earlier today stay in the day\'s file');
        $this->assertSame(1, $second['marked']);
        $this->assertSame(['000000001', '123456789', '223456789'], $this->bucketPeople());

        $viceApprover = collect($this->bucketRows())->firstWhere('personal_id', '123456789');
        $this->assertSame([3, 4], array_column($viceApprover['booking_type_roles'], 'role_id'));
    }

    public function test_switching_the_hatch_in_the_middle_of_the_day_loses_nobody(): void
    {
        // Hatch off: the bucket way sends the three people. Then a role changes and the hatch is switched on.
        $this->hatchOff();
        app(UsersExportService::class)->exportToBucket();

        $this->grantApprover(self::VICE_APPROVER, [['booking_type_id' => 2, 'is_approver' => true, 'is_vice_approver' => true]]);
        $this->hatchOn();

        $viaHatch = app(UsersExportService::class)->export();

        $this->assertSame([], $viaHatch['errors']);
        $this->assertSame(1, $viaHatch['built']['users']);
        $this->assertSame(1, $viaHatch['marked']);
        $this->assertSame(
            ['000000001', '123456789', '223456789'],
            array_column(json_decode($this->ftp()->get(self::RED_TO_BLACK . $this->usersFileName()), true)['users'], 'personal_id'),
            'the FTP gets the whole day\'s file, with the two people only the bucket way had sent'
        );

        // And back: another change, the hatch off again.
        $this->grantApprover(self::PLAIN_USER, [['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => true]]);
        $this->hatchOff();

        $back = app(UsersExportService::class)->exportToBucket();

        $this->assertSame(1, $back['built']['users']);
        $this->assertSame(3, $back['built']['in_file']);
        $this->assertSame(1, $back['marked']);
    }

    public function test_a_bucket_that_cannot_be_written_keeps_the_people_waiting(): void
    {
        $this->hatchOff();
        $this->partialMock(StorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('putJsonToBucket')->andThrow(new \RuntimeException('bucket full'));
        });

        $failed = app(UsersExportService::class)->exportToBucket();

        $this->assertStringContainsString('bucket full', $failed['errors'][0]);
        $this->assertSame(0, $failed['marked']);
        $this->assertFalse($this->sent(self::VICE_APPROVER), 'not written, so not marked');
        $this->assertSame(0, DigitalHatchSentFile::where('destination', 'bucket')->count(), 'not written, so not remembered');

        $this->artisan('exportUsersJson:bucket')->expectsOutputToContain('bucket full')->assertExitCode(1);

        // The bucket is back: the same people were still waiting.
        $this->app->forgetInstance(StorageService::class);
        $ok = app(UsersExportService::class)->exportToBucket();

        $this->assertSame([], $ok['errors']);
        $this->assertSame(3, $ok['marked']);
        $this->assertSame(['000000001', '123456789', '223456789'], $this->bucketPeople());
    }

    public function test_the_two_ways_take_the_same_lock(): void
    {
        $lock = Cache::lock('digital-hatch:send-users', 30);
        $this->assertTrue($lock->get());

        $this->hatchOff();
        $this->assertSame(['skipped' => 'busy'], app(UsersExportService::class)->exportToBucket());
        $this->assertFalse($this->sent(self::VICE_APPROVER));

        $this->hatchOn();
        $this->assertTrue(app(UsersExportService::class)->export()['busy']);

        $lock->release();
        $this->assertSame([], app(UsersExportService::class)->export()['errors']);
        $this->assertTrue($this->sent(self::VICE_APPROVER));
    }

    public function test_the_command_says_what_it_did(): void
    {
        $this->hatchOff();

        $this->artisan('exportUsersJson:bucket')
            ->expectsOutputToContain("Users file written (3 waiting, 3 in the day's file)")
            ->expectsOutputToContain('Marked as exported (is_exported_role = 1): 3 users.')
            ->assertExitCode(0);

        $this->artisan('exportUsersJson:bucket')
            ->expectsOutputToContain('no person is waiting')
            ->expectsOutputToContain('Marked as exported (is_exported_role = 1): 0 users.')
            ->assertExitCode(0);

        $this->hatchOn();

        $this->artisan('exportUsersJson:bucket')->expectsOutputToContain('the digital hatch is on')->assertExitCode(0);
    }
}

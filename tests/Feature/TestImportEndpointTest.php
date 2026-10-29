<?php

namespace Tests\Feature;

use App\Enums\HttpStatusCodeEnum;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Services\StorageService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;

/** GET /api/test-import: the work of the scheduled import commands, done right now, with the imported data in the answer. */
class TestImportEndpointTest extends ImportJsonTestCase
{
    /** Two kinds of booking, one with a comment, and three logs of the first two. */
    private function putFiles(): void
    {
        $this->putBookingFile([
            $this->foodBooking(1001, ['comments' => [['comment' => 'מאושר', 'personal_id' => self::ACTOR]]]),
            $this->barberBooking(1002, '2027-01-03', '09:00', ['booking_owner_personal_id' => self::OTHER_OWNER]),
            $this->foodBooking(1003),
        ]);

        $this->putLogFile([
            $this->logRecord(1001, 1, self::OWNER),
            $this->logRecord(1001, 4, self::ACTOR, self::CREATED_AT + 60, statusId: 3),
            $this->logRecord(1002, 1, self::OTHER_OWNER),
        ]);
    }

    private function callAsSuperAdmin(string $query = ''): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber(self::SUPER_ADMIN)->getJson('/api/test-import' . $query);
    }

    public function test_it_imports_both_files_and_returns_what_was_imported(): void
    {
        $this->putFiles();

        $response = $this->callAsSuperAdmin()->assertOk();

        // What the two imports did, and that the files were loaded.
        $response->assertJsonPath('data.import.bookings.files.0.file', self::BOOKINGS_FILE)
            ->assertJsonPath('data.import.bookings.files.0.rows', 3)
            ->assertJsonPath('data.import.bookings.files.0.stats.created', 3)
            ->assertJsonPath('data.import.bookings.files.0.moved_to', self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE)
            ->assertJsonPath('data.import.bookings.errors', [])
            ->assertJsonPath('data.import.application_logs.files.0.stats.created', 3)
            ->assertJsonPath('data.import.application_logs.files.0.stats.booking_not_found', 0)
            ->assertJsonPath('data.import.application_logs.files.0.moved_to', self::LOADED_DIRECTORY . '/' . self::LOGS_FILE);

        Storage::disk('minio')->assertMissing(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
        Storage::disk('minio')->assertMissing(self::LOG_DIRECTORY . '/' . self::LOGS_FILE);
        Storage::disk('minio')->assertExists(self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE);
        Storage::disk('minio')->assertExists(self::LOADED_DIRECTORY . '/' . self::LOGS_FILE);

        // The data itself.
        $response->assertJsonPath('data.totals.bookings', 3)
            ->assertJsonPath('data.totals.application_logs', 3)
            ->assertJsonPath('data.totals.barber_bookings', 1)
            ->assertJsonPath('data.totals.booking_values', 12)
            ->assertJsonPath('data.totals.comments', 1)
            ->assertJsonPath('data.bookings_by_type', ['5' => 1, '11' => 2])
            ->assertJsonPath('data.bookings_by_status', ['1' => 3])
            ->assertJsonPath('data.application_logs_by_action', ['1' => 2, '4' => 1])
            ->assertJsonPath('data.bookings.total', 3);

        $first = $response->json('data.bookings.data.0');

        $this->assertSame(1001, $first['serial_number']);
        $this->assertSame(11, $first['booking_type']['id']);
        $this->assertSame(1, $first['status']['id']);
        $this->assertSame(self::OWNER, $first['owner']['personal_id']);
        $this->assertSame('2026-10-29 07:06:11', $first['created_at']);
        $this->assertContains(['form_field_id' => 6, 'field' => 'שם אירוע', 'option_field_id' => null, 'option' => null, 'value' => 'ישיבת עבודה'], $first['values']);
        $this->assertCount(5, $first['values']);
        $this->assertSame([['personal_id' => self::ACTOR, 'comment' => 'מאושר']], $first['comments']);
        $this->assertSame(['store_booking', 'approve_booking'], array_column($first['application_logs'], 'action'));
        $this->assertSame([self::OWNER, self::ACTOR], array_column($first['application_logs'], 'personal_id'));
        $this->assertNull($first['barber_slot']);

        $barber = $response->json('data.bookings.data.1');
        $this->assertSame(5, $barber['booking_type']['id']);
        $this->assertSame('2027-01-03', substr($barber['barber_slot']['date'], 0, 10));
        $this->assertSame('09:00', substr($barber['barber_slot']['start_time'], 0, 5));
    }

    public function test_the_logs_are_saved_after_the_bookings_they_belong_to(): void
    {
        $this->putFiles();

        $this->callAsSuperAdmin()->assertOk();

        $this->assertSame(0, ApplicationLog::whereNotIn('booking_id', Booking::pluck('id'))->count());
        $this->assertSame(3, ApplicationLog::count());
    }

    public function test_calling_it_again_with_nothing_waiting_just_shows_the_data(): void
    {
        $this->putFiles();
        $this->callAsSuperAdmin()->assertOk();

        $again = $this->callAsSuperAdmin()->assertOk();

        $again->assertJsonPath('data.import.bookings.files', [])
            ->assertJsonPath('data.import.application_logs.files', [])
            ->assertJsonPath('data.totals.bookings', 3)
            ->assertJsonPath('data.totals.application_logs', 3);
    }

    public function test_it_pages_and_filters_the_imported_bookings(): void
    {
        $this->putFiles();
        $this->callAsSuperAdmin()->assertOk();

        $secondPage = $this->callAsSuperAdmin('?per_page=1&page=2')->assertOk();
        $this->assertSame([1002], array_column($secondPage->json('data.bookings.data'), 'serial_number'));
        $secondPage->assertJsonPath('data.bookings.last_page', 3);

        $barbers = $this->callAsSuperAdmin('?booking_type_id=5')->assertOk();
        $this->assertSame([1002], array_column($barbers->json('data.bookings.data'), 'serial_number'));
        $barbers->assertJsonPath('data.bookings.total', 1);

        $byPrefix = $this->callAsSuperAdmin('?serial_number=100')->assertOk();
        $this->assertSame([1001, 1002, 1003], array_column($byPrefix->json('data.bookings.data'), 'serial_number'));

        $bySerial = $this->callAsSuperAdmin('?serial_number=1003')->assertOk();
        $this->assertSame([1003], array_column($bySerial->json('data.bookings.data'), 'serial_number'));

        // The totals are of everything imported, whatever page or filter is shown.
        $bySerial->assertJsonPath('data.totals.bookings', 3);
    }

    public function test_it_refuses_a_bad_query_before_it_touches_the_bucket(): void
    {
        $this->putFiles();

        foreach (['?per_page=51', '?per_page=0', '?page=0', '?booking_type_id=999', '?booking_type_id=abc', '?serial_number=abc'] as $query) {
            $this->callAsSuperAdmin($query)->assertUnprocessable();
        }

        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
        $this->assertSame(0, Booking::count());
    }

    public function test_only_the_super_admin_may_call_it(): void
    {
        $this->putFiles();

        foreach ([self::ADMIN, self::APPROVER, self::VICE_APPROVER, self::PLAIN_USER, self::NO_ROLE] as $personalNumber) {
            $this->app['auth']->forgetGuards();
            $this->actingAsPersonalNumber($personalNumber)->getJson('/api/test-import')->assertForbidden();
        }

        // Nobody logged in (the client would otherwise send the token of the last person above).
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/test-import')->assertUnauthorized();

        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_busy_folder_is_reported_in_the_answer(): void
    {
        $this->putFiles();
        $lock = Cache::lock('import-json:EXPORT_BOOKING', 60);
        $this->assertTrue($lock->get());

        $this->callAsSuperAdmin()
            ->assertOk()
            ->assertJsonPath('data.import.bookings.busy', true)
            ->assertJsonPath('data.import.bookings.files', [])
            ->assertJsonPath('data.import.application_logs.busy', false);

        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
    }

    public function test_a_file_that_cannot_be_read_is_in_the_answer_and_stays_in_the_inbox(): void
    {
        Storage::disk('minio')->put(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE, 'not json');

        $response = $this->callAsSuperAdmin()->assertOk();

        $response->assertJsonPath('data.import.bookings.files', [])
            ->assertJsonPath('data.import.bookings.errors.0.file', self::BOOKINGS_FILE);
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
    }

    public function test_switching_the_import_off_shows_the_data_without_reading_the_bucket(): void
    {
        $this->putFiles();
        config(['app.import_json.enabled' => false]);

        $this->callAsSuperAdmin()
            ->assertOk()
            ->assertJsonPath('data.import.bookings.disabled', true)
            ->assertJsonPath('data.import.application_logs.disabled', true)
            ->assertJsonPath('data.totals.bookings', 0);

        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
    }

    /** A bucket the disk refuses to list - e.g. one that does not exist (NoSuchBucket). */
    private function bucketThatCannotBeListed(): void
    {
        $this->mock(StorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listJsonFilesInBucket')->andThrow(new \RuntimeException('bucket down'));
            $mock->shouldReceive('logToS3')->andReturn(200);
        });
    }

    public function test_a_bucket_that_cannot_be_listed_is_a_500_with_the_usual_message(): void
    {
        config(['app.debug' => false]);
        $this->bucketThatCannotBeListed();

        $this->callAsSuperAdmin()
            ->assertStatus(500)
            ->assertExactJson(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()]);
    }

    public function test_with_debug_on_the_500_also_says_why(): void
    {
        config(['app.debug' => true]);
        $this->bucketThatCannotBeListed();

        $this->callAsSuperAdmin()
            ->assertStatus(500)
            ->assertExactJson([
                'message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage(),
                'error' => 'bucket down',
            ]);
    }

    public function test_both_import_commands_are_scheduled_the_logs_a_minute_after_the_bookings(): void
    {
        Artisan::call('schedule:list');

        $events = collect(app(Schedule::class)->events());
        $bookings = $events->first(fn ($event) => str_contains($event->command, 'importBookingJson:mysql'));
        $logs = $events->first(fn ($event) => str_contains($event->command, 'importApplicationLogJson:mysql'));

        $this->assertNotNull($bookings, 'the booking import is scheduled');
        $this->assertNotNull($logs, 'the application log import is scheduled');
        $this->assertSame('*/5 * * * *', $bookings->expression);
        $this->assertSame('1-59/5 * * * *', $logs->expression);
        $this->assertTrue($bookings->withoutOverlapping && $logs->withoutOverlapping);
        $this->assertSame(config('app.timezone'), $bookings->timezone);
    }
}

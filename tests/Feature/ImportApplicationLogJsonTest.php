<?php

namespace Tests\Feature;

use App\Enums\ActionTypeEnum;
use App\Jobs\ImportApplicationLogJsonChunkJob;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\User;
use App\Services\ImportApplicationLogService;
use App\Services\ImportBookingService;
use App\Services\StorageService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/** The application log files in import-json/application-log: a log belongs to a booking, so the bookings come first. */
class ImportApplicationLogJsonTest extends ImportJsonTestCase
{
    private function importLogs(): array
    {
        return app(ImportApplicationLogService::class)->importFromBucket(sync: true);
    }

    private function importBookings(): array
    {
        return app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    /** Save two bookings the logs below belong to. */
    private function saveBookings(): void
    {
        $this->putBookingFile([$this->foodBooking(1001), $this->foodBooking(1002, ['booking_owner_personal_id' => self::OTHER_OWNER])]);
        $this->importBookings();
    }

    public function test_a_log_is_saved_with_its_action_person_and_time(): void
    {
        $this->saveBookings();
        $known = User::where('personal_id', self::OWNER)->firstOrFail();
        $usersBefore = User::count();

        $this->putLogFile([
            $this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER, self::CREATED_AT),
            $this->logRecord(1001, ActionTypeEnum::VICE_APPROVER_PROCESSING_BOOKING->value, self::ACTOR, self::CREATED_AT + 60, statusId: 15),
            $this->logRecord(1001, ActionTypeEnum::APPROVE_BOOKING->value, self::ACTOR, self::CREATED_AT + 120, statusId: 3),
            $this->logRecord(1002, ActionTypeEnum::STORE_BOOKING->value, self::OTHER_OWNER, self::CREATED_AT),
        ]);

        $result = $this->importLogs();

        $this->assertSame([], $result['errors']);
        $this->assertSame(['created' => 4, 'already_saved' => 0, 'skipped' => 0, 'booking_not_found' => 0], $result['files'][0]['stats']);
        $this->assertSame(self::LOADED_DIRECTORY . '/' . self::LOGS_FILE, $result['files'][0]['moved_to']);
        Storage::disk('minio')->assertMissing(self::LOG_DIRECTORY . '/' . self::LOGS_FILE);

        $logs = ApplicationLog::where('booking_id', Booking::where('serial_number', 1001)->value('id'))->orderBy('id')->get();

        $this->assertSame([1, 3, 4], $logs->pluck('action_type_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($known->id, (int) $logs[0]->user_id);
        $this->assertSame(User::where('personal_id', self::ACTOR)->value('id'), (int) $logs[1]->user_id);
        $this->assertSame(
            ['2026-10-29 07:06:11', '2026-10-29 07:07:11', '2026-10-29 07:08:11'],
            $logs->map(fn (ApplicationLog $log) => $log->getRawOriginal('created_at'))->all(),
            'the unix time of the export'
        );
        $this->assertSame($logs->map(fn ($log) => $log->getRawOriginal('created_at'))->all(), $logs->map(fn ($log) => $log->getRawOriginal('updated_at'))->all());
        $this->assertTrue($logs->every(fn (ApplicationLog $log) => $log->is_exported), 'nothing is sent back to the mobile app');

        // The one person the web did not know yet is a bare user row now, in the unit of the booking.
        $this->assertSame($usersBefore + 1, User::count());
        $this->assertSame(1, (int) User::where('personal_id', self::ACTOR)->value('unit_id'));
    }

    public function test_reading_the_same_log_file_again_adds_nothing(): void
    {
        $this->saveBookings();
        $logs = [
            $this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER),
            $this->logRecord(1001, ActionTypeEnum::APPROVE_BOOKING->value, self::ACTOR, self::CREATED_AT + 120),
        ];

        $this->putLogFile($logs);
        $this->importLogs();

        $this->putLogFile($logs);
        $second = $this->importLogs();

        $this->assertSame(['created' => 0, 'already_saved' => 2, 'skipped' => 0, 'booking_not_found' => 0], $second['files'][0]['stats']);
        $this->assertSame(2, ApplicationLog::count());
    }

    public function test_the_same_action_at_another_time_is_another_log(): void
    {
        $this->saveBookings();

        $this->putLogFile([
            $this->logRecord(1001, ActionTypeEnum::UPDATE_BOOKING->value, self::OWNER, self::CREATED_AT + 10),
            $this->logRecord(1001, ActionTypeEnum::UPDATE_BOOKING->value, self::OWNER, self::CREATED_AT + 20),
            $this->logRecord(1001, ActionTypeEnum::UPDATE_BOOKING->value, self::ACTOR, self::CREATED_AT + 10),
        ]);

        $this->assertSame(3, $this->importLogs()['files'][0]['stats']['created']);
    }

    public function test_a_log_whose_booking_is_not_saved_yet_waits_and_is_saved_once_the_booking_arrives(): void
    {
        $this->saveBookings();

        $this->putLogFile([
            $this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER),
            $this->logRecord(2002, ActionTypeEnum::STORE_BOOKING->value, self::ACTOR),   // booking 2002 comes in a later file
        ]);

        $first = $this->importLogs();

        $this->assertSame(1, $first['files'][0]['stats']['created']);
        $this->assertSame(1, $first['files'][0]['stats']['booking_not_found']);
        $this->assertSame(1, ApplicationLog::count());
        $this->assertNull(User::where('personal_id', self::ACTOR)->first(), 'nobody is created for a log that was not saved');

        // The booking arrives; the job looks again at the same rows.
        $this->putBookingFile([$this->foodBooking(2002)], 'SrvOnClick_b2r_export_bookings_2026-10-30.json');
        $this->importBookings();

        $chunk = [
            $this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER),
            $this->logRecord(2002, ActionTypeEnum::STORE_BOOKING->value, self::ACTOR),
        ];
        $stats = app(ImportApplicationLogService::class)->importChunk($chunk, 0);

        $this->assertSame(['created' => 1, 'already_saved' => 1, 'skipped' => 0, 'booking_not_found' => 0], $stats);
        $this->assertSame(2, ApplicationLog::count());
    }

    public function test_rows_that_cannot_be_logs_are_skipped_and_the_others_are_saved(): void
    {
        $this->saveBookings();

        $this->putLogFile([
            $this->logRecord(1001, 99, self::OWNER),                                                   // an action this system does not have
            $this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, 'abc'),                       // not an ID number
            [...$this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER), 'created_at' => null],
            [...$this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER), 'serial_number' => null],
            'not an object',
            $this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER),                 // the only good one
        ]);

        $result = $this->importLogs();

        $this->assertSame(['created' => 1, 'already_saved' => 0, 'skipped' => 5, 'booking_not_found' => 0], $result['files'][0]['stats']);
        $this->assertSame(1, ApplicationLog::count());
    }

    public function test_the_job_waits_and_looks_again_while_a_booking_is_missing_and_gives_up_on_the_last_try(): void
    {
        config(['app.jobs.import_application_log_json' => ['tries' => 5, 'backoff' => 60, 'timeout' => 900]]);
        $chunk = [$this->logRecord(3003, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)];

        $job = (new ImportApplicationLogJsonChunkJob($chunk, 0))->withFakeQueueInteractions();
        $stats = $job->handle();

        $this->assertSame(1, $stats['booking_not_found']);
        $job->assertReleased(delay: 60);

        // On the last try there is nobody left to wait for.
        config(['app.jobs.import_application_log_json.tries' => 1]);
        $last = (new ImportApplicationLogJsonChunkJob($chunk, 0))->withFakeQueueInteractions();
        $last->handle();

        $last->assertNotReleased();
        $this->assertSame(0, ApplicationLog::count());
    }

    public function test_the_job_does_not_wait_when_every_booking_is_there(): void
    {
        $this->saveBookings();

        $job = (new ImportApplicationLogJsonChunkJob([$this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)], 0))->withFakeQueueInteractions();
        $stats = $job->handle();

        $this->assertSame(1, $stats['created']);
        $job->assertNotReleased();
    }

    public function test_the_queued_path_dispatches_one_job_per_chunk(): void
    {
        $this->saveBookings();
        Queue::fake();
        config(['app.import_json.application_log_chunk_size' => 2]);

        $this->putLogFile(array_map(
            fn (int $offset): array => $this->logRecord(1001, ActionTypeEnum::UPDATE_BOOKING->value, self::OWNER, self::CREATED_AT + $offset),
            [1, 2, 3, 4, 5]
        ));

        $result = app(ImportApplicationLogService::class)->importFromBucket();

        Queue::assertPushed(ImportApplicationLogJsonChunkJob::class, 3);
        $this->assertSame(0, ApplicationLog::count());
        $this->assertSame(self::LOADED_DIRECTORY . '/' . self::LOGS_FILE, $result['files'][0]['moved_to']);

        Queue::pushed(ImportApplicationLogJsonChunkJob::class)->each(fn (ImportApplicationLogJsonChunkJob $job) => $job->handle());

        $this->assertSame(5, ApplicationLog::count());
    }

    public function test_the_log_folder_and_the_booking_folder_are_read_separately(): void
    {
        $this->saveBookings();

        // A booking file dropped in the log folder (and the other way round) is not what that folder's import reads.
        Storage::disk('minio')->put(self::LOG_DIRECTORY . '/SrvOnClick_b2r_export_bookings_2026-10-30.json', json_encode(['bookings' => [$this->foodBooking(4004)]]));
        $this->putLogFile([$this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)]);

        $result = $this->importLogs();

        $this->assertSame([self::LOGS_FILE], array_column($result['files'], 'file'));
        $this->assertNull(Booking::where('serial_number', 4004)->first());
        Storage::disk('minio')->assertExists(self::LOG_DIRECTORY . '/SrvOnClick_b2r_export_bookings_2026-10-30.json');
    }

    public function test_the_command_reads_the_bucket_and_can_be_switched_off(): void
    {
        $this->saveBookings();
        $this->putLogFile([$this->logRecord(1001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)]);

        config(['app.import_json.enabled' => false]);
        $this->assertSame(0, Artisan::call('importApplicationLogJson:mysql'));
        $this->assertSame(0, ApplicationLog::count());
        Storage::disk('minio')->assertExists(self::LOG_DIRECTORY . '/' . self::LOGS_FILE);

        config(['app.import_json.enabled' => true]);
        $this->assertSame(0, Artisan::call('importApplicationLogJson:mysql'));
        $this->assertStringContainsString('1 logs in 1 chunks queued', Artisan::output());
        $this->assertSame(1, ApplicationLog::count());
        Storage::disk('minio')->assertExists(self::LOADED_DIRECTORY . '/' . self::LOGS_FILE);
    }

    public function test_the_command_fails_when_a_file_cannot_be_read(): void
    {
        Storage::disk('minio')->put(self::LOG_DIRECTORY . '/' . self::LOGS_FILE, json_encode(['rows' => []]));

        $this->assertSame(1, Artisan::call('importApplicationLogJson:mysql'));
        Storage::disk('minio')->assertExists(self::LOG_DIRECTORY . '/' . self::LOGS_FILE);
    }

    public function test_the_command_says_why_when_the_bucket_cannot_be_listed(): void
    {
        $this->mock(StorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listJsonFilesInBucket')->andThrow(new \RuntimeException('bucket down'));
            $mock->shouldReceive('logToS3')->andReturn(200);
        });

        $this->assertSame(1, Artisan::call('importApplicationLogJson:mysql'));
        $this->assertStringContainsString('bucket down', Artisan::output());
    }
}

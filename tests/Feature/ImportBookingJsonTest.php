<?php

namespace Tests\Feature;

use App\Enums\SystemReferenceEnum;
use App\Jobs\ImportBookingJsonChunkJob;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\Comment;
use App\Models\User;
use App\Services\ImportBookingService;
use App\Services\StorageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/** The booking files in import-json: what is saved, what is skipped, and that reading a file twice changes nothing. */
class ImportBookingJsonTest extends ImportJsonTestCase
{
    private function import(): array
    {
        return app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    /** The booking_values rows of a booking as [form field, option, value], by form field and option. */
    private function valueRows(int $serialNumber): array
    {
        $bookingId = Booking::where('serial_number', $serialNumber)->value('id');

        return BookingValue::where('booking_id', $bookingId)
            ->orderBy('form_field_id')->orderBy('option_field_id')
            ->get()
            ->map(fn (BookingValue $value): array => [(int) $value->form_field_id, $value->option_field_id === null ? null : (int) $value->option_field_id, $value->value])
            ->all();
    }

    private function slotOf(int $serialNumber): BarberBooking
    {
        return BarberBooking::where('booking_id', Booking::where('serial_number', $serialNumber)->value('id'))->firstOrFail();
    }

    /** Minutes of one barber slot, as the seeded system references have them: a haircut plus the cleaning after it. */
    private function barberSlotMinutes(): int
    {
        $reference = fn (SystemReferenceEnum $case, string $key): int => (int) SystemReferenceEnum::getSystemReferenceValue(5, $case->getSystemReferenceId())[$key];

        return $reference(SystemReferenceEnum::BARBER_HAIRCUT_TIME, 'haircut_time') + $reference(SystemReferenceEnum::BARBER_CLEAN_TIME, 'clean_time');
    }

    public function test_a_full_record_saves_the_booking_with_its_values_comments_and_people(): void
    {
        // The web knows this person already (from the login); the owner and the other commenter are new to it.
        $known = User::factory()->create(['personal_id' => self::ACTOR, 'unit_id' => 1]);
        $usersBefore = User::count();

        $this->putBookingFile([
            $this->foodBooking(1001, ['status_id' => 3, 'comments' => [
                ['comment' => 'מאושר', 'personal_id' => self::ACTOR],
                ['comment' => 'נא לעדכן', 'personal_id' => self::OTHER_OWNER],
            ]]),
        ]);

        $result = $this->import();

        $this->assertFalse($result['busy']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['files']);
        $this->assertSame(self::BOOKINGS_FILE, $result['files'][0]['file']);
        $this->assertSame(1, $result['files'][0]['rows']);
        $this->assertSame(1, $result['files'][0]['stats']['created']);
        $this->assertSame(2, $result['files'][0]['stats']['comments_added']);
        $this->assertSame(0, $result['files'][0]['stats']['values_skipped']);

        // The file was read: it left the inbox and is in loaded-json.
        Storage::disk('minio')->assertMissing(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
        Storage::disk('minio')->assertExists(self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE);
        $this->assertSame(self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE, $result['files'][0]['moved_to']);

        $booking = Booking::where('serial_number', 1001)->firstOrFail();
        $owner = User::where('personal_id', self::OWNER)->firstOrFail();

        $this->assertSame(11, (int) $booking->booking_type_id);
        $this->assertSame(3, (int) $booking->status_id);
        $this->assertSame(1, (int) $booking->unit_id, 'the unit of the form fields');
        $this->assertSame($owner->id, (int) $booking->user_id);
        $this->assertSame(1, (int) $owner->unit_id, 'a person the web did not know gets the unit of their booking');
        $this->assertNotEmpty($booking->uuid);
        $this->assertSame('2026-10-29 07:06:11', $booking->getRawOriginal('created_at'), 'the unix time of the export');
        $this->assertSame('2026-10-29 07:06:11', $booking->getRawOriginal('updated_at'));
        $this->assertTrue($booking->is_exported && $booking->is_status_exported && !$booking->is_updated_value, 'nothing is sent back to the mobile app');

        $this->assertSame([
            [6, null, 'ישיבת עבודה'],
            [7, 3, null],
            [8, null, '50'],
            [9, null, '2026-11-09'],
            [10, null, '11:41'],
        ], $this->valueRows(1001));

        $comments = Comment::where('booking_id', $booking->id)->orderBy('id')->get();
        $this->assertSame(['מאושר', 'נא לעדכן'], $comments->pluck('comment')->all());
        $this->assertSame($known->id, (int) $comments[0]->user_id, 'a known person is not created again');
        $this->assertSame(User::where('personal_id', self::OTHER_OWNER)->value('id'), (int) $comments[1]->user_id);
        $this->assertSame($usersBefore + 2, User::count(), 'only the owner and the other commenter were created');
    }

    public function test_values_keep_only_the_leaves_of_the_option_and_field_trees(): void
    {
        $this->putBookingFile([
            // 33 > 35 > 40 is a chain of categories; the options 84 > 93 are a chain too: only the end of each chain is a row.
            $this->bookingRecord(2001, 3, [
                $this->node(33, null, 5),
                $this->node(35, 33, 5),
                $this->node(40, 35, 5, null, [$this->option(84, 40), $this->option(93, 40, 84)]),
                $this->node(43, 35, 1, '2'),
                $this->node(34, null, 5),
                $this->node(49, 34, 5),
            ]),
            // A field the user ticked several things of: one row for each, every one with its own value.
            $this->bookingRecord(2002, 10, [
                $this->node(18, null, 5),
                $this->node(31, 18, 20, null, [
                    $this->option(13, 31, null, 1),
                    $this->option(14, 31, null, 3),
                    $this->option(15, 31, null, 1),
                    $this->option(16, 31, null, 4),
                ]),
            ]),
        ]);

        $this->import();

        $this->assertSame([
            [40, 93, null],
            [43, null, '2'],
            [49, null, null],
        ], $this->valueRows(2001));

        $this->assertSame([
            [31, 13, '1'],
            [31, 14, '3'],
            [31, 15, '1'],
            [31, 16, '4'],
        ], $this->valueRows(2002));
    }

    public function test_an_id_number_is_matched_the_way_the_login_matches_it(): void
    {
        // The row keeps nine digits with the leading zero; the export may not.
        $existing = User::factory()->create(['personal_id' => '012345678', 'unit_id' => 1]);
        $usersBefore = User::count();

        $this->putBookingFile([$this->foodBooking(3001, ['booking_owner_personal_id' => '12345678'])]);
        $this->import();

        $this->assertSame($existing->id, (int) Booking::where('serial_number', 3001)->value('user_id'));
        $this->assertSame($usersBefore, User::count());
    }

    public function test_reading_the_same_file_again_changes_nothing(): void
    {
        $this->putBookingFile([
            $this->foodBooking(4001, ['comments' => [['comment' => 'שלום', 'personal_id' => self::ACTOR]]]),
            $this->barberBooking(4002, '2027-01-03', '09:00'),
        ]);
        $this->import();

        $counts = [Booking::count(), BookingValue::count(), Comment::count(), BarberBooking::count(), User::count()];

        $this->putBookingFile([
            $this->foodBooking(4001, ['comments' => [['comment' => 'שלום', 'personal_id' => self::ACTOR]]]),
            $this->barberBooking(4002, '2027-01-03', '09:00'),
        ]);
        $second = $this->import();

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'skipped' => 0, 'failed' => 0, 'values_skipped' => 0, 'comments_added' => 0, 'attachments_added' => 0], $second['files'][0]['stats']);
        $this->assertSame($counts, [Booking::count(), BookingValue::count(), Comment::count(), BarberBooking::count(), User::count()]);

        // The first copy is not overwritten: the second one keeps its name plus the time it was loaded.
        $loaded = Storage::disk('minio')->files(self::LOADED_DIRECTORY);
        $this->assertCount(2, $loaded);
        $this->assertContains(self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE, $loaded);
    }

    public function test_a_change_record_brings_the_new_status_the_date_and_time_and_only_the_new_comments(): void
    {
        $this->putBookingFile([
            $this->foodBooking(5001, ['comments' => [['comment' => 'ראשונה', 'personal_id' => self::ACTOR]]]),
        ]);
        $this->import();

        $later = self::CREATED_AT + 3600;

        // The file of the next day: the booking is not exported in full again, only what changed.
        $this->putBookingFile([
            $this->changeRecord(
                5001,
                3,
                $later,
                values: [$this->node(9, null, 3, '2026-11-10'), $this->node(10, null, 4, '12:00')],
                comments: [
                    ['comment' => 'ראשונה', 'personal_id' => self::ACTOR],
                    ['comment' => 'שנייה', 'personal_id' => self::ACTOR],
                ],
            ),
        ], 'SrvOnClick_b2r_export_bookings_2026-10-30.json');

        $result = $this->import();

        $this->assertSame(1, $result['files'][0]['stats']['updated']);
        $this->assertSame(1, $result['files'][0]['stats']['comments_added']);

        $booking = Booking::where('serial_number', 5001)->firstOrFail();
        $this->assertSame(3, (int) $booking->status_id);
        $this->assertSame('2026-10-29 08:06:11', $booking->getRawOriginal('updated_at'));
        $this->assertSame('2026-10-29 07:06:11', $booking->getRawOriginal('created_at'));
        $this->assertSame(11, (int) $booking->booking_type_id, 'the type and the owner stay as they were');

        $this->assertSame([
            [6, null, 'ישיבת עבודה'],
            [7, 3, null],
            [8, null, '50'],
            [9, null, '2026-11-10'],
            [10, null, '12:00'],
        ], $this->valueRows(5001));

        $this->assertSame(['ראשונה', 'שנייה'], Comment::where('booking_id', $booking->id)->orderBy('id')->pluck('comment')->all());
    }

    public function test_a_file_older_than_what_is_saved_does_not_take_the_booking_back(): void
    {
        $this->putBookingFile([$this->foodBooking(6001, ['status_id' => 3, 'updated_at' => (string) (self::CREATED_AT + 7200)])]);
        $this->import();

        // A late file: the status it brings is from before the one saved.
        $this->putBookingFile([$this->changeRecord(6001, 4, self::CREATED_AT + 60)], 'SrvOnClick_b2r_export_bookings_2026-10-28.json');
        $result = $this->import();

        $this->assertSame(1, $result['files'][0]['stats']['unchanged']);
        $this->assertSame(3, (int) Booking::where('serial_number', 6001)->value('status_id'));
    }

    public function test_rows_that_cannot_be_bookings_are_skipped_and_the_others_are_saved(): void
    {
        $this->putBookingFile([
            $this->bookingRecord(7001, 999, $this->foodValues()),                          // a booking type this system does not have
            $this->foodBooking(7002, ['booking_owner_personal_id' => 'abc']),               // not an ID number
            $this->foodBooking(7003, ['status_id' => 99]),                                  // a status this system does not have
            $this->foodBooking(7004, ['serial_number' => null]),                            // no serial number
            $this->changeRecord(7005, 3, self::CREATED_AT),                                 // news about a booking this system never got
            'not an object',
            $this->foodBooking(7006),                                                        // the only good one
        ]);

        $result = $this->import();

        $this->assertSame(1, $result['files'][0]['stats']['created']);
        $this->assertSame(6, $result['files'][0]['stats']['skipped']);
        $this->assertSame(0, $result['files'][0]['stats']['failed']);
        $this->assertSame([7006], Booking::pluck('serial_number')->map(fn ($serial) => (int) $serial)->all());
        $this->assertNotNull($result['files'][0]['moved_to'], 'the rows that were skipped do not keep the file in the inbox');
    }

    public function test_a_serial_number_cannot_change_the_booking_type_of_a_saved_booking(): void
    {
        $this->putBookingFile([$this->foodBooking(7101)]);
        $this->import();

        $this->putBookingFile([$this->bookingRecord(7101, 3, [$this->node(33, null, 5)], ['status_id' => 3])], 'SrvOnClick_b2r_export_bookings_2026-10-30.json');
        $result = $this->import();

        $this->assertSame(1, $result['files'][0]['stats']['skipped']);
        $booking = Booking::where('serial_number', 7101)->firstOrFail();
        $this->assertSame([11, 1], [(int) $booking->booking_type_id, (int) $booking->status_id]);
    }

    public function test_a_value_of_another_booking_type_is_skipped_but_the_booking_is_saved(): void
    {
        $this->putBookingFile([
            // 83 is the date of a barber booking, not a field of an in-unit food booking.
            $this->bookingRecord(8001, 11, [...$this->foodValues(), $this->node(83, null, 3, '2027-01-03')]),
        ]);

        $result = $this->import();

        $this->assertSame(1, $result['files'][0]['stats']['created']);
        $this->assertSame(1, $result['files'][0]['stats']['values_skipped']);
        $this->assertCount(5, $this->valueRows(8001));
    }

    public function test_a_barber_booking_gets_its_slot_and_a_status_that_frees_the_slot_marks_it_deleted(): void
    {
        $this->putBookingFile([
            $this->barberBooking(9001, '2027-01-03', '09:00'),                           // received
            $this->barberBooking(9002, '2027-01-04', '10:15', ['status_id' => 6]),       // canceled by the user: the slot is free again
            $this->barberBooking(9003, '2027-01-05', '11:00', ['status_id' => 4]),       // rejected: the mobile app keeps the slot
        ]);

        $this->import();

        $minutes = $this->barberSlotMinutes();
        $this->assertGreaterThan(0, $minutes);

        $slot = $this->slotOf(9001);
        $this->assertSame('2027-01-03', substr((string) $slot->date, 0, 10));
        $this->assertSame('09:00', substr($slot->start_time, 0, 5));
        $this->assertSame(Carbon::createFromFormat('H:i', '09:00')->addMinutes($minutes)->format('H:i'), substr($slot->end_time, 0, 5), 'a haircut plus the cleaning');
        $this->assertSame(Booking::where('serial_number', 9001)->value('user_id'), $slot->user_id, 'the slot is the owner\'s');

        $this->assertFalse((bool) $slot->is_deleted);
        $this->assertTrue((bool) $this->slotOf(9002)->is_deleted);
        $this->assertFalse((bool) $this->slotOf(9003)->is_deleted);
        $this->assertSame(3, BarberBooking::count());
    }

    public function test_a_change_record_moves_a_barber_slot_and_frees_it_when_the_booking_ends(): void
    {
        $this->putBookingFile([$this->barberBooking(9101, '2027-01-03', '09:00')]);
        $this->import();

        $this->putBookingFile([
            $this->changeRecord(9101, 11, self::CREATED_AT + 600, values: [$this->node(83, null, 3, '2027-01-10'), $this->node(84, null, 4, '10:00')]),
        ], 'SrvOnClick_b2r_export_bookings_2026-10-30.json');
        $this->import();

        $slot = $this->slotOf(9101);
        $this->assertSame('2027-01-10', substr((string) $slot->date, 0, 10));
        $this->assertSame('10:00', substr($slot->start_time, 0, 5));
        $this->assertSame(Carbon::createFromFormat('H:i', '10:00')->addMinutes($this->barberSlotMinutes())->format('H:i'), substr($slot->end_time, 0, 5));
        $this->assertTrue((bool) $slot->is_deleted, 'approved by the barber = done, the slot is free');
        $this->assertSame(1, BarberBooking::count());
    }

    public function test_a_chunk_with_a_failing_row_saves_the_others_and_throws_so_the_job_is_tried_again(): void
    {
        $chunk = [$this->foodBooking(10001), $this->foodBooking(10002), $this->foodBooking(10003)];
        $failing = true;

        Booking::creating(function (Booking $booking) use (&$failing): void {
            if ($failing && (int) $booking->serial_number === 10002) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            try {
                app(ImportBookingService::class)->importChunk($chunk, 0);
                $this->fail('a chunk with a failed row must throw');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('1 of 3 bookings could not be saved', $e->getMessage());
                $this->assertStringContainsString('boom', $e->getMessage());
            }

            $this->assertEqualsCanonicalizing([10001, 10003], Booking::pluck('serial_number')->map(fn ($serial) => (int) $serial)->all(), 'the other rows were saved');
            $this->assertSame(0, Comment::count() + BookingValue::whereNotIn('booking_id', Booking::pluck('id'))->count(), 'the failed row left nothing behind');

            // The retry: what is saved stays as it is, the failed row is saved now.
            $failing = false;
            $stats = app(ImportBookingService::class)->importChunk($chunk, 0);

            $this->assertSame(1, $stats['created']);
            $this->assertSame(2, $stats['unchanged']);
            $this->assertSame(3, Booking::count());
        } finally {
            Booking::flushEventListeners();
            Booking::clearBootedModels();
        }
    }

    public function test_the_queued_path_dispatches_one_job_per_chunk_and_the_jobs_save_the_bookings(): void
    {
        Queue::fake();
        config(['app.import_json.booking_chunk_size' => 2]);

        $this->putBookingFile(array_map(fn (int $serial): array => $this->foodBooking($serial), [11001, 11002, 11003, 11004, 11005]));

        $result = app(ImportBookingService::class)->importFromBucket();

        Queue::assertPushed(ImportBookingJsonChunkJob::class, 3);
        $this->assertSame(3, $result['files'][0]['chunks']);
        $this->assertSame(self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE, $result['files'][0]['moved_to'], 'the file is loaded once every chunk is in the queue');
        $this->assertSame(0, Booking::count(), 'nothing is saved until the queue worker runs the jobs');

        Queue::pushed(ImportBookingJsonChunkJob::class)->each(fn (ImportBookingJsonChunkJob $job) => $job->handle());

        $this->assertSame(5, Booking::count());
    }

    public function test_the_job_takes_its_retry_settings_from_the_config(): void
    {
        config(['app.jobs.import_booking_json' => ['tries' => 7, 'backoff' => 11, 'timeout' => 123]]);

        $job = new ImportBookingJsonChunkJob([], 0);

        $this->assertSame([7, 11, 123], [$job->tries, $job->backoff, $job->timeout]);
    }

    public function test_a_second_run_leaves_the_folder_alone_while_another_one_is_reading_it(): void
    {
        $this->putBookingFile([$this->foodBooking(12001)]);

        $lock = Cache::lock('import-json:EXPORT_BOOKING', 60);
        $this->assertTrue($lock->get());

        $busy = $this->import();

        $this->assertTrue($busy['busy']);
        $this->assertSame([], $busy['files']);
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
        $this->assertSame(0, Booking::count());

        $lock->release();

        $this->assertFalse($this->import()['busy']);
        $this->assertSame(1, Booking::count());
    }

    public function test_a_file_that_cannot_be_read_is_reported_and_left_in_place_while_the_others_are_imported(): void
    {
        Storage::disk('minio')->put(self::BOOKING_DIRECTORY . '/SrvOnClick_b2r_export_bookings_2026-10-27.json', '{"bookings": [');
        Storage::disk('minio')->put(self::BOOKING_DIRECTORY . '/SrvOnClick_b2r_export_bookings_2026-10-28.json', json_encode(['rows' => []]));
        $this->putBookingFile([$this->foodBooking(13001)]);

        $result = $this->import();

        $this->assertCount(1, $result['files']);
        $this->assertCount(2, $result['errors']);
        $this->assertSame(['SrvOnClick_b2r_export_bookings_2026-10-27.json', 'SrvOnClick_b2r_export_bookings_2026-10-28.json'], array_column($result['errors'], 'file'));
        $this->assertStringContainsString('missing the bookings array', $result['errors'][1]['message']);

        $this->assertSame(1, Booking::count());
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/SrvOnClick_b2r_export_bookings_2026-10-27.json');
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/SrvOnClick_b2r_export_bookings_2026-10-28.json');
        Storage::disk('minio')->assertMissing(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
    }

    public function test_files_are_read_oldest_first_and_only_the_booking_files_are_touched(): void
    {
        // Put in the wrong order on purpose: the name decides, not the order they arrived in.
        $this->putBookingFile([$this->changeRecord(14001, 3, self::CREATED_AT + 3600)], 'SrvOnClick_b2r_export_bookings_2026-10-29.json');
        $this->putBookingFile([$this->foodBooking(14001)], 'SrvOnClick_b2r_export_bookings_2026-10-28.json');

        $strays = [
            self::BOOKING_DIRECTORY . '/notes.txt',
            self::BOOKING_DIRECTORY . '/SrvOnClick_b2r_booking_status_2026-10-29.json',
            self::BOOKING_DIRECTORY . '/SrvOnClick_b2r_export_bookings_2026-10-30.txt',
        ];
        foreach ($strays as $stray) {
            Storage::disk('minio')->put($stray, '{"bookings": []}');
        }

        $result = $this->import();

        $this->assertSame(
            ['SrvOnClick_b2r_export_bookings_2026-10-28.json', 'SrvOnClick_b2r_export_bookings_2026-10-29.json'],
            array_column($result['files'], 'file')
        );
        $this->assertSame(3, (int) Booking::where('serial_number', 14001)->value('status_id'), 'the change came after the full record');

        foreach ($strays as $stray) {
            Storage::disk('minio')->assertExists($stray);
        }
    }

    public function test_outside_prod_the_bucket_name_is_part_of_every_object_key(): void
    {
        config(['app.prod_stage' => false, 'filesystems.disks.minio.bucket' => 'srvonclick-bucket']);

        Storage::disk('minio')->put(
            'srvonclick-bucket/' . self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE,
            json_encode(['bookings' => [$this->foodBooking(15001)]])
        );

        $this->import();

        $this->assertSame(1, Booking::count());
        Storage::disk('minio')->assertMissing('srvonclick-bucket/' . self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
        Storage::disk('minio')->assertExists('srvonclick-bucket/' . self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE);
    }

    public function test_the_command_reads_the_bucket_and_can_be_switched_off(): void
    {
        $this->putBookingFile([$this->foodBooking(16001)]);

        config(['app.import_json.enabled' => false]);
        $this->assertSame(0, Artisan::call('importBookingJson:bucket', ['--sync' => true]));
        $this->assertStringContainsString('switched off', Artisan::output());
        $this->assertSame(0, Booking::count());
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);

        config(['app.import_json.enabled' => true]);
        $this->assertSame(0, Artisan::call('importBookingJson:bucket', ['--sync' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('1 bookings in 1 chunks saved (1 created)', $output);
        $this->assertStringContainsString('file moved to ' . self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE, $output);
        $this->assertSame(1, Booking::count());
        Storage::disk('minio')->assertExists(self::LOADED_DIRECTORY . '/' . self::BOOKINGS_FILE);
    }

    public function test_the_command_fails_when_a_file_cannot_be_read(): void
    {
        Storage::disk('minio')->put(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE, 'not json');

        $this->assertSame(1, Artisan::call('importBookingJson:bucket', ['--sync' => true]));
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
    }

    public function test_the_command_says_why_when_the_bucket_cannot_be_listed(): void
    {
        $this->mock(StorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listJsonFilesInBucket')->andThrow(new \RuntimeException('bucket down'));
            $mock->shouldReceive('logToS3')->andReturn(200);
        });

        $this->assertSame(1, Artisan::call('importBookingJson:bucket', ['--sync' => true]));
        $this->assertStringContainsString('bucket down', Artisan::output());
    }
}

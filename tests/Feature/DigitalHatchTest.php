<?php

namespace Tests\Feature;

use App\Enums\ActionTypeEnum;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\DigitalHatchFetchedFile;
use App\Models\File;
use App\Services\DigitalHatchService;
use App\Services\ImportBookingService;
use App\Services\StorageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

/**
 * The b2r side of the digital hatch with the FTP a folder in memory: what the mobile side puts there is taken into the bucket folders the importers
 * read, saved in the database and moved to loaded-json (the ":ftp" import commands, DigitalHatchService). The local test folders and the commands
 * and jobs themselves are in HatchImportCommandsTest; the r2b side, one stream at a time, is in DigitalHatchSendTest.
 */
class DigitalHatchTest extends HatchTestCase
{
    private const BOOKINGS_ON_FTP = 'SrvOnClick_b2r_export_bookings_2026-11-02_08-54-11.json';
    private const LOGS_ON_FTP = 'SrvOnClick_b2r_application_logs_2026-11-02_08-54-17.json';

    private const PDF = 'SrvOnClickFiles_6adf010810404_j0iNc8FqHf';
    private const DOC = 'SrvOnClickFiles_0b3f8e2a-5c1d-4e7f-9a62-3d8c41f7b9e0';

    //----------------------------- Taking the files and importing them ------------------------------------//

    public function test_the_ftp_import_takes_the_new_b2r_files_saves_them_and_moves_them_to_loaded_json_only_once(): void
    {
        $bookings = json_encode(['bookings' => [$this->foodBooking(5101)]]);
        $logs = json_encode(['application_logs' => [$this->logRecord(5101, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)]]);

        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, $bookings);
        $this->ftp()->put(self::BLACK_TO_RED . self::LOGS_ON_FTP, $logs);
        // Not for this system: our own r2b file, another stream, another prefix, another type.
        $this->ftp()->put(self::BLACK_TO_RED . 'SrvOnClick_r2b_users_2026-11-02.json', '{"users":[]}');
        $this->ftp()->put(self::BLACK_TO_RED . 'SrvOnClick_b2r_something_else_2026-11-02.json', '{"x":[]}');
        $this->ftp()->put(self::BLACK_TO_RED . 'srvonclick_b2r_export_bookings_2026-11-02.json', $bookings);
        $this->ftp()->put(self::BLACK_TO_RED . 'SrvOnClick_b2r_export_bookings_2026-11-02_10-00-00.txt', $bookings);

        $service = app(DigitalHatchService::class);

        $taken = $service->importBookings(sync: true);

        $this->assertSame([], $taken['errors']);
        $this->assertSame('FTP folder /' . self::BLACK_TO_RED, $taken['source']);
        $this->assertSame([self::BOOKINGS_ON_FTP], $taken['fetched']);
        $this->assertSame([], $taken['imported']['errors']);
        $this->assertSame(['file' => self::BOOKINGS_ON_FTP, 'rows' => 1, 'chunks' => 1, 'moved_to' => 'loaded-json/' . self::BOOKINGS_ON_FTP], array_intersect_key(
            $taken['imported']['files'][0], array_flip(['file', 'rows', 'chunks', 'moved_to'])
        ));
        $this->assertTrue(Booking::where('serial_number', 5101)->exists());

        // The logs belong to a booking, so they come second.
        $taken = $service->importApplicationLogs(sync: true);

        $this->assertSame([self::LOGS_ON_FTP], $taken['fetched']);
        $this->assertSame(1, ApplicationLog::count());

        // The folder the importers read is empty again, the files are in loaded-json under the names they had on the FTP.
        $this->assertSame([], $this->bucket()->files('import-json'));
        $this->assertEqualsCanonicalizing(
            ['loaded-json/' . self::BOOKINGS_ON_FTP, 'loaded-json/' . self::LOGS_ON_FTP],
            $this->bucket()->files('loaded-json')
        );

        // The ledger knows each file by its name, its stream and where it came from; the FTP is never cleaned.
        $this->assertSame([
            ['file_name' => self::LOGS_ON_FTP, 'stream' => 'application_logs', 'source' => 'ftp'],
            ['file_name' => self::BOOKINGS_ON_FTP, 'stream' => 'export_bookings', 'source' => 'ftp'],
        ], DigitalHatchFetchedFile::orderBy('file_name')->get(['file_name', 'stream', 'source'])->toArray());
        $this->assertTrue($this->ftp()->exists(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP));

        // Taken once: the second run finds nothing new.
        $again = $service->importBookings(sync: true);
        $this->assertSame([], $again['fetched']);
        $this->assertSame([], $again['imported']['files']);
    }

    public function test_a_file_still_being_uploaded_waits_for_the_next_run(): void
    {
        $partial = 'SrvOnClick_b2r_export_bookings_2026-11-02_09-00-00.json';
        $this->ftp()->put(self::BLACK_TO_RED . $partial, '{"bookings": [');

        $first = app(DigitalHatchService::class)->importBookings(sync: true);

        $this->assertSame([], $first['fetched']);
        $this->assertStringContainsString($partial, $first['errors'][0]);
        $this->assertSame([], $this->bucket()->files('import-json'));
        $this->assertSame(0, DigitalHatchFetchedFile::count(), 'not marked, so it is tried again');

        $this->ftp()->put(self::BLACK_TO_RED . $partial, json_encode(['bookings' => []]));
        $second = app(DigitalHatchService::class)->importBookings(sync: true);

        $this->assertSame([], $second['errors']);
        $this->assertSame([$partial], $second['fetched']);
        $this->assertTrue($this->bucket()->exists('loaded-json/' . $partial));
    }

    public function test_force_takes_the_files_again_and_reading_a_booking_again_changes_nothing(): void
    {
        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, json_encode(['bookings' => [$this->foodBooking(5201)]]));
        $service = app(DigitalHatchService::class);

        $this->assertSame([self::BOOKINGS_ON_FTP], $service->importBookings(sync: true)['fetched']);
        $this->assertSame([], $service->importBookings(sync: true)['fetched']);

        $again = $service->importBookings(force: true, sync: true);

        $this->assertSame([self::BOOKINGS_ON_FTP], $again['fetched']);
        $this->assertSame(1, $again['imported']['files'][0]['stats']['unchanged'], 'the booking is found by its serial number');
        $this->assertSame(1, Booking::where('serial_number', 5201)->count());
        $this->assertSame(1, DigitalHatchFetchedFile::count(), 'one row per file name');
        $this->assertCount(2, $this->bucket()->files('loaded-json'), 'the second copy got the time in its name');
    }

    public function test_a_file_imported_from_the_bucket_is_noted_in_the_ledger_and_a_later_ftp_take_of_that_name_is_skipped(): void
    {
        $this->putBookingFile([$this->foodBooking(5701)], self::BOOKINGS_ON_FTP);
        $this->putLogFile([$this->logRecord(5701, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)], self::LOGS_ON_FTP);

        app(ImportBookingService::class)->importFromBucket(sync: true);
        app(\App\Services\ImportApplicationLogService::class)->importFromBucket(sync: true);

        $this->assertEqualsCanonicalizing([
            ['file_name' => self::BOOKINGS_ON_FTP, 'stream' => 'export_bookings', 'source' => 'bucket'],
            ['file_name' => self::LOGS_ON_FTP, 'stream' => 'application_logs', 'source' => 'bucket'],
        ], DigitalHatchFetchedFile::get(['file_name', 'stream', 'source'])->toArray());

        // The same name on the FTP was imported already: it is not taken again, unless that is asked for.
        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, json_encode(['bookings' => [$this->foodBooking(5701)]]));
        $service = app(DigitalHatchService::class);

        $this->assertSame([], $service->importBookings(sync: true)['fetched']);

        $this->assertSame([self::BOOKINGS_ON_FTP], $service->importBookings(force: true, sync: true)['fetched']);
        $this->assertSame('ftp', DigitalHatchFetchedFile::where('file_name', self::BOOKINGS_ON_FTP)->value('source'), 'taken again on purpose: the row says how it came now');
        $this->assertSame(2, DigitalHatchFetchedFile::count());
    }

    public function test_the_import_still_reads_the_bucket_when_the_ftp_cannot_be_reached(): void
    {
        $this->putBookingFile([$this->foodBooking(5301)]);
        $this->useUnreachableFtp();

        $result = app(DigitalHatchService::class)->importBookings(sync: true);

        $this->assertNotSame([], $result['errors'], 'the FTP error is not hidden');
        $this->assertSame([], $result['fetched']);
        $this->assertTrue(Booking::where('serial_number', 5301)->exists(), 'what already waits in the bucket is imported anyway');
    }

    public function test_a_file_is_not_landed_when_the_ledger_cannot_be_written(): void
    {
        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, json_encode(['bookings' => [$this->foodBooking(5501)]]));

        // A dev database whose migration was not run again: the ledger has no stream column yet.
        Schema::table('digital_hatch_fetched_files', function (Blueprint $table): void {
            $table->dropIndex(['stream']);
            $table->dropColumn('stream');
        });

        try {
            $result = app(DigitalHatchService::class)->importBookings(sync: true);
        } finally {
            Schema::table('digital_hatch_fetched_files', function (Blueprint $table): void {
                $table->string('stream', 30)->default('')->index();
            });
        }

        $this->assertSame([], $result['fetched']);
        $this->assertStringContainsString('digital_hatch_fetched_files cannot be written', $result['errors'][0]);
        $this->assertSame([], $this->bucket()->files('import-json'), 'nothing landed, so nothing is taken twice');
        $this->assertSame([], $result['imported']['files']);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_file_that_could_not_be_landed_is_not_remembered_so_the_next_run_takes_it_again(): void
    {
        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, json_encode(['bookings' => [$this->foodBooking(5601)]]));
        $this->partialMock(StorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('putJsonToBucket')->once()->andThrow(new \RuntimeException('bucket full'));
        });

        $result = app(DigitalHatchService::class)->importBookings(sync: true);

        $this->assertSame([], $result['fetched']);
        $this->assertStringContainsString('bucket full', $result['errors'][0]);
        $this->assertSame(0, DigitalHatchFetchedFile::count());
    }

    //----------------------------- The files and images ------------------------------------//

    public function test_the_ftp_import_of_files_takes_only_the_files_the_bookings_can_name_and_serve_and_saves_them_when_their_booking_is_there(): void
    {
        $this->putBookingFile([$this->foodBooking(5401, ['files' => [['file_id' => 7, 'path' => self::PDF]]])]);
        app(ImportBookingService::class)->importFromBucket(sync: true);

        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', '%PDF-1.4 a');
        $this->ftp()->put(self::ATTACHMENTS . self::DOC . '.docx', 'PK docx');
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.exe', 'MZ');
        $this->ftp()->put(self::ATTACHMENTS . 'SrvOnClickFiles_with space.pdf', 'x');
        $this->ftp()->put(self::ATTACHMENTS . 'Other_abc.pdf', 'x');
        $this->ftp()->put(self::ATTACHMENTS . 'srvonclickfiles_abc.pdf', 'x');

        $result = app(DigitalHatchService::class)->importFiles();

        $this->assertSame([], $result['errors']);
        $this->assertSame('FTP folder /' . self::ATTACHMENTS, $result['source']);
        $this->assertSame([self::DOC . '.docx', self::PDF . '.pdf'], $result['fetched']);

        // The file a booking names is saved: copied to files, the received one moved to loaded-files, the row knows where its bytes are.
        $this->assertSame('%PDF-1.4 a', $this->bucket()->get('files/' . self::PDF . '.pdf'));
        $this->assertSame('%PDF-1.4 a', $this->bucket()->get('loaded-files/' . self::PDF . '.pdf'));
        $this->assertSame('files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame(1, $result['imported']['linked']);
        $this->assertSame(1, $result['imported']['moved']);
        $this->assertSame(2, $result['imported']['in_bucket']);

        // The document no booking names yet waits in the inbox, copied nowhere.
        $this->assertSame(1, $result['imported']['unclaimed']);
        $this->assertSame(['import-files/' . self::DOC . '.docx'], $this->bucket()->files('import-files'));
        $this->assertSame(['files/' . self::PDF . '.pdf'], $this->bucket()->files('files'));

        $this->assertEqualsCanonicalizing([
            ['file_name' => self::PDF . '.pdf', 'stream' => 'attachments', 'source' => 'ftp'],
            ['file_name' => self::DOC . '.docx', 'stream' => 'attachments', 'source' => 'ftp'],
        ], DigitalHatchFetchedFile::where('stream', 'attachments')->get(['file_name', 'stream', 'source'])->toArray());

        // Its booking arrives later: the next run saves it from the inbox, nothing is taken from the FTP again.
        $this->putBookingFile([$this->foodBooking(5402, ['files' => [['file_id' => 8, 'path' => self::DOC]]])]);
        app(ImportBookingService::class)->importFromBucket(sync: true);

        $again = app(DigitalHatchService::class)->importFiles();

        $this->assertSame([], $again['fetched'], 'taken once');
        $this->assertSame(1, $again['imported']['moved']);
        $this->assertSame('files/' . self::DOC . '.docx', File::where('name', self::DOC)->value('path'));
        $this->assertSame([], $this->bucket()->files('import-files'));
        $this->assertSame('PK docx', $this->bucket()->get('loaded-files/' . self::DOC . '.docx'));
    }

    //----------------------------- Switches, output and failures ------------------------------------//

    public function test_nothing_is_taken_while_the_hatch_is_disabled(): void
    {
        config(['services.digital-hatch.enabled' => false]);

        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, json_encode(['bookings' => []]));
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', 'x');

        $service = app(DigitalHatchService::class);
        $this->assertSame(['enabled' => false], $service->importBookings());
        $this->assertSame(['enabled' => false], $service->importApplicationLogs());
        $this->assertSame(['enabled' => false], $service->importFiles());
        $this->assertSame([], $this->bucket()->allFiles());

        foreach (['importBookingJson:ftp', 'importApplicationLogJson:ftp', 'importFile:ftp'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['--sync' => true]));
            $this->assertStringContainsString('switched off (HATCH_ENABLED=false)', Artisan::output());
        }
    }

    public function test_the_ftp_commands_report_what_they_took_and_what_became_of_it(): void
    {
        $this->ftp()->put(self::BLACK_TO_RED . self::LOGS_ON_FTP, json_encode(['application_logs' => []]));
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', 'x');

        $this->assertSame(0, Artisan::call('importApplicationLogJson:ftp', ['--sync' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Source: FTP folder /' . self::BLACK_TO_RED, $output);
        $this->assertStringContainsString('Taken into the bucket: ' . self::LOGS_ON_FTP, $output);
        $this->assertStringContainsString(self::LOGS_ON_FTP . ': 0 logs in 0 chunks saved, file moved to loaded-json/' . self::LOGS_ON_FTP, $output);

        $this->assertSame(0, Artisan::call('importFile:ftp', ['--sync' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Taken into the bucket: ' . self::PDF . '.pdf', $output);
        $this->assertStringContainsString('Files received in import-files: 1.', $output);

        $this->assertSame(0, Artisan::call('importApplicationLogJson:ftp', ['--sync' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('No new file in the source', $output);
        $this->assertStringContainsString('no file is waiting in import-json', $output);
        $this->assertSame(0, Artisan::call('importFile:ftp', ['--sync' => true]));
        $this->assertStringContainsString('No new file in the source', Artisan::output());
    }

    public function test_an_unreachable_ftp_fails_the_import_instead_of_looking_like_an_empty_folder(): void
    {
        $this->useUnreachableFtp();

        $service = app(DigitalHatchService::class);
        $this->assertNotSame([], $service->importBookings(sync: true)['errors']);
        $this->assertNotSame([], $service->importApplicationLogs(sync: true)['errors']);
        $this->assertNotSame([], $service->importFiles()['errors']);

        foreach (['importBookingJson:ftp', 'importApplicationLogJson:ftp', 'importFile:ftp'] as $command) {
            $this->assertSame(1, Artisan::call($command, ['--sync' => true]));
        }
    }
}

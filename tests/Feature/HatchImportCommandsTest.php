<?php

namespace Tests\Feature;

use App\Enums\ActionTypeEnum;
use App\Jobs\ImportApplicationLogJsonBucketJob;
use App\Jobs\ImportApplicationLogJsonFtpJob;
use App\Jobs\ImportBookingJsonBucketJob;
use App\Jobs\ImportBookingJsonFtpJob;
use App\Jobs\ImportFileBucketJob;
use App\Jobs\ImportFileFtpJob;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\DigitalHatchFetchedFile;
use App\Models\File;
use App\Models\Image;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The six import commands - importBookingJson, importApplicationLogJson and importFile, each ":bucket" or ":ftp" (where the files come from) - and
 * the job each one queues: by default the job is queued, --sync imports in the command and shows the result. The ":ftp" ones are tested here on
 * local folders (DIGITAL_HATCH_IMPORT_PATH, DIGITAL_HATCH_IMPORT_FILES_PATH) that take the place of the FTP, the way the developer runs them;
 * DigitalHatchTest has the FTP itself.
 */
class HatchImportCommandsTest extends HatchTestCase
{
    private const BOOKINGS_FILE_ON_DISK = 'SrvOnClick_b2r_export_bookings_2026-11-02_14-47-34.json';
    private const LOGS_FILE_ON_DISK = 'SrvOnClick_b2r_application_logs_2026-11-02_14-47-51.json';

    private const PDF = 'SrvOnClickFiles_6adf010810404_j0iNc8FqHf';
    private const DOC = 'SrvOnClickFiles_77aa3b_form';
    private const PHOTO = 'SrvOnClickFiles_9c01de_photo';

    private function putLocalBookings(array $bookings, string $name = self::BOOKINGS_FILE_ON_DISK): void
    {
        file_put_contents($this->localJsonDir . '/' . $name, json_encode(['bookings' => $bookings], JSON_UNESCAPED_UNICODE));
    }

    private function putLocalLogs(array $logs, string $name = self::LOGS_FILE_ON_DISK): void
    {
        file_put_contents($this->localJsonDir . '/' . $name, json_encode(['application_logs' => $logs], JSON_UNESCAPED_UNICODE));
    }

    /** A booking that names a pdf and a document (files) and a photo (an image), as the mobile export does: no extension. */
    private function putBookingWithAttachments(int $serial): void
    {
        $this->putBookingFile([
            $this->foodBooking($serial, [
                'files' => [['file_id' => 7, 'path' => self::PDF], ['file_id' => 8, 'path' => self::DOC]],
                'images' => [['image_id' => 3, 'path' => self::PHOTO]],
            ]),
        ]);
    }

    //----------------------------- ":ftp" on the local folders ------------------------------------//

    public function test_importBookingJson_ftp_reads_the_local_folder_saves_the_bookings_and_moves_the_file_to_loaded_json(): void
    {
        $this->useLocalImportFolders();
        $this->putLocalBookings([$this->foodBooking(6101), $this->foodBooking(6102)]);
        // Other files in the folder are not for this command.
        file_put_contents($this->localJsonDir . '/notes.txt', 'x');
        file_put_contents($this->localJsonDir . '/SrvOnClick_r2b_users_2026-11-02.json', '{"users":[]}');
        $this->putLocalLogs([]);

        $this->assertSame(0, Artisan::call('importBookingJson:ftp', ['--sync' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Source: local folder ' . $this->localJsonDir, $output);
        $this->assertStringContainsString('Taken into the bucket: ' . self::BOOKINGS_FILE_ON_DISK, $output);
        $this->assertStringContainsString(
            self::BOOKINGS_FILE_ON_DISK . ': 2 bookings in 1 chunks saved (2 created',
            $output
        );
        $this->assertStringContainsString('file moved to loaded-json/' . self::BOOKINGS_FILE_ON_DISK, $output);

        $this->assertSame(2, Booking::count());
        $this->assertSame([], $this->bucket()->files('import-json/booking-data'));
        $this->assertSame(['loaded-json/' . self::BOOKINGS_FILE_ON_DISK], $this->bucket()->files('loaded-json'));
        $this->assertFalse($this->bucket()->exists('import-json/application-log/' . self::LOGS_FILE_ON_DISK), 'the log file is for the other command');

        $this->assertSame(
            ['file_name' => self::BOOKINGS_FILE_ON_DISK, 'stream' => 'export_bookings', 'source' => 'local'],
            DigitalHatchFetchedFile::firstOrFail()->only(['file_name', 'stream', 'source'])
        );

        // The folder is never written to or cleaned, and the FTP is not touched.
        $this->assertTrue(is_file($this->localJsonDir . '/' . self::BOOKINGS_FILE_ON_DISK));
        $this->assertSame([], $this->ftp()->allFiles());
    }

    public function test_a_file_taken_before_is_skipped_until_force_takes_it_again(): void
    {
        $this->useLocalImportFolders();
        $this->putLocalBookings([$this->foodBooking(6201)]);

        $this->assertSame(0, Artisan::call('importBookingJson:ftp', ['--sync' => true]));

        $this->assertSame(0, Artisan::call('importBookingJson:ftp', ['--sync' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('No new file in the source', $output);
        $this->assertStringContainsString('Nothing to import: no file is waiting in import-json/booking-data', $output);

        $this->assertSame(0, Artisan::call('importBookingJson:ftp', ['--sync' => true, '--force' => true]));
        $this->assertStringContainsString('Taken into the bucket: ' . self::BOOKINGS_FILE_ON_DISK, Artisan::output());
        $this->assertSame(1, Booking::count(), 'the booking is found by its serial number');
        $this->assertSame(1, DigitalHatchFetchedFile::count(), 'one row per file name');
        $this->assertCount(2, $this->bucket()->files('loaded-json'));
    }

    public function test_importApplicationLogJson_ftp_saves_the_logs_of_bookings_that_are_already_saved(): void
    {
        $this->useLocalImportFolders();
        $this->putLocalBookings([$this->foodBooking(6301)]);
        $this->putLocalLogs([$this->logRecord(6301, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)]);

        $this->assertSame(0, Artisan::call('importBookingJson:ftp', ['--sync' => true]));
        $this->assertSame(0, ApplicationLog::count(), 'the booking command does not take the log file');

        $this->assertSame(0, Artisan::call('importApplicationLogJson:ftp', ['--sync' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Taken into the bucket: ' . self::LOGS_FILE_ON_DISK, $output);
        $this->assertStringContainsString(self::LOGS_FILE_ON_DISK . ': 1 logs in 1 chunks saved (1 created)', $output);
        $this->assertStringContainsString('file moved to loaded-json/' . self::LOGS_FILE_ON_DISK, $output);
        $this->assertSame(1, ApplicationLog::count());
        $this->assertEqualsCanonicalizing(
            ['loaded-json/' . self::BOOKINGS_FILE_ON_DISK, 'loaded-json/' . self::LOGS_FILE_ON_DISK],
            $this->bucket()->files('loaded-json')
        );
        $this->assertSame('application_logs', DigitalHatchFetchedFile::where('file_name', self::LOGS_FILE_ON_DISK)->value('stream'));
    }

    public function test_importFile_ftp_takes_the_prefixed_files_from_the_local_folder_and_links_them_to_the_bookings_that_name_them(): void
    {
        $this->useLocalImportFolders();
        $this->putBookingWithAttachments(6401);
        $this->assertSame(0, Artisan::call('importBookingJson:bucket', ['--sync' => true]));

        file_put_contents($this->localFilesDir . '/' . self::PDF . '.pdf', '%PDF-1.4 a');
        file_put_contents($this->localFilesDir . '/' . self::PHOTO . '.jpg', 'jpeg bytes');
        // Not taken: another prefix, a type nobody serves, a name that is not plain.
        file_put_contents($this->localFilesDir . '/Other_abc.pdf', 'x');
        file_put_contents($this->localFilesDir . '/' . self::DOC . '.exe', 'MZ');
        file_put_contents($this->localFilesDir . '/SrvOnClickFiles_with space.pdf', 'x');

        $this->assertSame(0, Artisan::call('importFile:ftp', ['--sync' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Source: local folder ' . $this->localFilesDir, $output);
        $this->assertStringContainsString('Taken into the bucket: ' . self::PDF . '.pdf', $output);
        $this->assertStringContainsString('Taken into the bucket: ' . self::PHOTO . '.jpg', $output);
        $this->assertStringContainsString(
            'Linked to their booking now: 2, linked before: 0, named by a booking but not in the bucket yet: 1, in the bucket but not named by a booking yet: 0.',
            $output
        );

        $this->assertSame('import-files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame('import-files/' . self::PHOTO . '.jpg', Image::where('name', self::PHOTO)->value('path'));
        $this->assertSame('import-files/' . self::DOC, File::where('name', self::DOC)->value('path'), 'its bytes have not arrived');
        $this->assertEqualsCanonicalizing(
            ['import-files/' . self::PDF . '.pdf', 'import-files/' . self::PHOTO . '.jpg'],
            $this->bucket()->files('import-files')
        );
        $this->assertSame('jpeg bytes', $this->bucket()->get('import-files/' . self::PHOTO . '.jpg'));

        $this->assertEqualsCanonicalizing([
            ['file_name' => self::PDF . '.pdf', 'stream' => 'attachments', 'source' => 'local'],
            ['file_name' => self::PHOTO . '.jpg', 'stream' => 'attachments', 'source' => 'local'],
        ], DigitalHatchFetchedFile::get(['file_name', 'stream', 'source'])->toArray());
        $this->assertTrue(is_file($this->localFilesDir . '/' . self::PDF . '.pdf'), 'the folder is never cleaned');
    }

    public function test_a_local_folder_that_does_not_exist_is_an_error_and_is_not_created(): void
    {
        $missing = sys_get_temp_dir() . '/hatch-missing-' . uniqid();
        config(['services.digital-hatch.local.import.path' => $missing, 'services.digital-hatch.local.import.files_path' => $missing]);

        foreach (['importBookingJson:ftp', 'importApplicationLogJson:ftp', 'importFile:ftp'] as $command) {
            $this->assertSame(1, Artisan::call($command, ['--sync' => true]));
            $this->assertStringContainsString("the local folder {$missing} does not exist", Artisan::output());
        }

        $this->assertDirectoryDoesNotExist($missing);
    }

    //----------------------------- ":bucket" ------------------------------------//

    public function test_importFile_bucket_links_what_is_in_the_bucket_folder_and_moves_nothing(): void
    {
        $this->putBookingWithAttachments(6501);
        $this->assertSame(0, Artisan::call('importBookingJson:bucket', ['--sync' => true]));

        $this->bucket()->put('import-files/' . self::PDF . '.pdf', 'pdf');
        $this->bucket()->put('import-files/SrvOnClickFiles_nobody_names_me.png', 'png');
        $this->bucket()->put('import-files/notes.txt', 'x');
        // An image row cannot take a pdf: the photo keeps waiting for a picture.
        $this->bucket()->put('import-files/' . self::PHOTO . '.pdf', 'x');

        $this->assertSame(0, Artisan::call('importFile:bucket', ['--sync' => true]));

        $this->assertStringContainsString(
            'Files in the bucket folder import-files: 3. Linked to their booking now: 1, linked before: 0, named by a booking but not in the bucket yet: 2, in the bucket but not named by a booking yet: 1.',
            Artisan::output()
        );
        $this->assertSame('import-files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame('import-files/' . self::DOC, File::where('name', self::DOC)->value('path'));
        $this->assertSame('import-files/' . self::PHOTO, Image::where('name', self::PHOTO)->value('path'));

        // Run again: what was linked is not linked twice. Nothing in the folder was moved or deleted.
        $this->assertSame(0, Artisan::call('importFile:bucket', ['--sync' => true]));
        $this->assertStringContainsString('Linked to their booking now: 0, linked before: 1, named by a booking but not in the bucket yet: 2', Artisan::output());
        $this->assertCount(4, $this->bucket()->files('import-files'));
    }

    public function test_the_old_mysql_names_still_run_the_bucket_imports(): void
    {
        $this->putBookingFile([$this->foodBooking(6601)]);
        $this->assertSame(0, Artisan::call('importBookingJson:mysql', ['--sync' => true]));
        $this->assertSame(1, Booking::count());

        $this->putLogFile([$this->logRecord(6601, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)]);
        $this->assertSame(0, Artisan::call('importApplicationLogJson:mysql', ['--sync' => true]));
        $this->assertSame(1, ApplicationLog::count());
    }

    //----------------------------- The queue ------------------------------------//

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function commandsAndTheirJobs(): array
    {
        return [
            'importBookingJson:bucket' => ['importBookingJson:bucket', ImportBookingJsonBucketJob::class],
            'importBookingJson:ftp' => ['importBookingJson:ftp', ImportBookingJsonFtpJob::class],
            'importApplicationLogJson:bucket' => ['importApplicationLogJson:bucket', ImportApplicationLogJsonBucketJob::class],
            'importApplicationLogJson:ftp' => ['importApplicationLogJson:ftp', ImportApplicationLogJsonFtpJob::class],
            'importFile:bucket' => ['importFile:bucket', ImportFileBucketJob::class],
            'importFile:ftp' => ['importFile:ftp', ImportFileFtpJob::class],
        ];
    }

    #[DataProvider('commandsAndTheirJobs')]
    public function test_a_command_only_queues_its_own_job_without_sync(string $command, string $jobClass): void
    {
        Queue::fake();
        $this->useLocalImportFolders();
        $this->putLocalBookings([$this->foodBooking(6701)]);
        $this->putBookingFile([$this->foodBooking(6702)]);

        $this->assertSame(0, Artisan::call($command));

        $this->assertStringContainsString(class_basename($jobClass) . ' queued', Artisan::output());
        Queue::assertPushed($jobClass, 1);
        Queue::assertCount(1);

        // Nothing was taken or saved yet: that is the job's work.
        $this->assertSame(0, DigitalHatchFetchedFile::count());
        $this->assertSame(0, Booking::count());
        $this->assertTrue($this->bucket()->exists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE));
    }

    public function test_force_is_handed_to_the_ftp_jobs_and_the_bucket_jobs_do_not_have_it(): void
    {
        Queue::fake();

        Artisan::call('importBookingJson:ftp', ['--force' => true]);
        Artisan::call('importApplicationLogJson:ftp', ['--force' => true]);
        Artisan::call('importFile:ftp');

        Queue::assertPushed(ImportBookingJsonFtpJob::class, fn (ImportBookingJsonFtpJob $job): bool => $job->force === true);
        Queue::assertPushed(ImportApplicationLogJsonFtpJob::class, fn (ImportApplicationLogJsonFtpJob $job): bool => $job->force === true);
        Queue::assertPushed(ImportFileFtpJob::class, fn (ImportFileFtpJob $job): bool => $job->force === false);

        $this->expectException(\Symfony\Component\Console\Exception\InvalidOptionException::class);
        Artisan::call('importBookingJson:bucket', ['--force' => true]);
    }

    public function test_nothing_is_queued_while_a_switch_is_off(): void
    {
        Queue::fake();

        config(['services.digital-hatch.enabled' => false]);
        foreach (['importBookingJson:ftp', 'importApplicationLogJson:ftp', 'importFile:ftp'] as $command) {
            $this->assertSame(0, Artisan::call($command));
            $this->assertStringContainsString('switched off (HATCH_ENABLED=false)', Artisan::output());
        }

        config(['services.digital-hatch.enabled' => true, 'app.import_json.enabled' => false]);
        foreach (['importBookingJson:bucket', 'importApplicationLogJson:bucket', 'importFile:bucket'] as $command) {
            $this->assertSame(0, Artisan::call($command));
            $this->assertStringContainsString('switched off (IMPORT_JSON_ENABLED=false)', Artisan::output());
        }

        Queue::assertNothingPushed();
    }

    public function test_without_sync_the_job_does_the_import_when_the_worker_picks_it_up(): void
    {
        $this->useLocalImportFolders();
        $this->putLocalBookings([$this->foodBooking(6801)]);

        // The test queue is sync, so the queued job runs at once, the way a worker would run it.
        $this->assertSame(0, Artisan::call('importBookingJson:ftp'));

        $this->assertStringContainsString('ImportBookingJsonFtpJob queued', Artisan::output());
        $this->assertTrue(Booking::where('serial_number', 6801)->exists());
        Storage::disk('minio')->assertExists('loaded-json/' . self::BOOKINGS_FILE_ON_DISK);
        $this->assertSame(1, DigitalHatchFetchedFile::count());
    }

    public function test_a_job_with_a_file_it_cannot_read_throws_so_the_queue_tries_again(): void
    {
        Storage::disk('minio')->put(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE, 'not json');

        $this->assertSame(1, Artisan::call('importBookingJson:bucket'), 'on the sync test queue the failure reaches the command');
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);

        try {
            (new ImportBookingJsonBucketJob())->handle();
            $this->fail('the job should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(self::BOOKINGS_FILE, $e->getMessage());
        }
    }

    public function test_an_ftp_job_throws_while_the_ftp_cannot_be_reached(): void
    {
        $this->useUnreachableFtp();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('listing');

        (new ImportFileFtpJob())->handle();
    }

    public function test_the_jobs_take_their_settings_from_the_config(): void
    {
        config(['app.jobs.import_job' => ['tries' => 7, 'backoff' => 13, 'timeout' => 99]]);

        foreach ([
            new ImportBookingJsonBucketJob(), new ImportBookingJsonFtpJob(true),
            new ImportApplicationLogJsonBucketJob(), new ImportApplicationLogJsonFtpJob(),
            new ImportFileBucketJob(), new ImportFileFtpJob(),
        ] as $job) {
            $this->assertSame([7, 13, 99], [$job->tries, $job->backoff, $job->timeout], $job::class);
        }
    }

    //----------------------------- The schedule ------------------------------------//

    public function test_the_ftp_imports_are_scheduled_the_logs_after_the_bookings_and_the_fetch_only_commands_are_gone(): void
    {
        Artisan::call('schedule:list');

        $events = collect(app(Schedule::class)->events());
        $find = fn (string $name) => $events->first(fn ($event) => str_contains($event->command, $name));

        $this->assertSame('2-59/5 * * * *', $find('importBookingJson:ftp')->expression);
        $this->assertSame('3-59/5 * * * *', $find('importApplicationLogJson:ftp')->expression);
        $this->assertSame('4-59/5 * * * *', $find('importFile:ftp')->expression);
        $this->assertNull($find('importFile:bucket'), 'linking what is in the bucket is run by hand');
        $this->assertNull($find('digital-hatch:fetch'), 'the :ftp imports take the files themselves');
    }
}

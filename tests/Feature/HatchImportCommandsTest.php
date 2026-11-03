<?php

namespace Tests\Feature;

use App\Enums\ActionTypeEnum;
use App\Jobs\ImportApplicationLogJsonChunkJob;
use App\Jobs\ImportBookingJsonChunkJob;
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
 * The six import commands - importBookingJson, importApplicationLogJson and importFile, each ":bucket" or ":ftp" (where the files come from).
 * The json commands read the file in the command and queue its rows as chunk jobs (a worker saves them); --sync saves the rows in the command.
 * The file commands queue one job each (--sync does the work in the command). The ":ftp" ones are tested here on local folders
 * (DIGITAL_HATCH_IMPORT_PATH, DIGITAL_HATCH_IMPORT_FILES_PATH) that take the place of the FTP, the way the developer runs them; DigitalHatchTest
 * has the FTP itself.
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
        $this->assertSame([], $this->bucket()->files('import-json'));
        $this->assertSame(['loaded-json/' . self::BOOKINGS_FILE_ON_DISK], $this->bucket()->files('loaded-json'));
        $this->assertFalse($this->bucket()->exists('import-json/' . self::LOGS_FILE_ON_DISK), 'the log file is for the other command');

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
        $this->assertStringContainsString('Nothing to import: no file is waiting in import-json', $output);

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

    public function test_importFile_ftp_takes_the_prefixed_files_from_the_local_folder_and_saves_them_with_the_bookings_that_name_them(): void
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
        $this->assertStringContainsString('Files received in import-files: 2. Saved now: 2 (copied to files, the received files moved to loaded-files), 2 file / image rows linked.', $output);
        $this->assertStringContainsString('Linked before: 0. Named by a booking but not received yet: 1. Received but not named by a booking yet: 0', $output);
        $this->assertStringContainsString('Not received yet: ' . self::DOC, $output);

        // The rows point at the copies in files, which hold the bytes; the received files are in loaded-files and the inbox is empty again.
        $this->assertSame('files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame('files/' . self::PHOTO . '.jpg', Image::where('name', self::PHOTO)->value('path'));
        $this->assertSame('files/' . self::DOC, File::where('name', self::DOC)->value('path'), 'its bytes have not arrived');
        $this->assertSame('%PDF-1.4 a', $this->bucket()->get('files/' . self::PDF . '.pdf'));
        $this->assertSame('jpeg bytes', $this->bucket()->get('files/' . self::PHOTO . '.jpg'));
        $this->assertEqualsCanonicalizing(['files/' . self::PDF . '.pdf', 'files/' . self::PHOTO . '.jpg'], $this->bucket()->files('files'));
        $this->assertEqualsCanonicalizing(['loaded-files/' . self::PDF . '.pdf', 'loaded-files/' . self::PHOTO . '.jpg'], $this->bucket()->files('loaded-files'));
        $this->assertSame([], $this->bucket()->files('import-files'));

        // The ledger knows how each came (the landing says "local"; the save that followed keeps that row).
        $this->assertEqualsCanonicalizing([
            ['file_name' => self::PDF . '.pdf', 'stream' => 'attachments', 'source' => 'local'],
            ['file_name' => self::PHOTO . '.jpg', 'stream' => 'attachments', 'source' => 'local'],
        ], DigitalHatchFetchedFile::where('stream', 'attachments')->get(['file_name', 'stream', 'source'])->toArray());
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

    public function test_importFile_bucket_saves_what_the_bookings_name_and_leaves_the_rest_in_the_inbox(): void
    {
        $this->putBookingWithAttachments(6501);
        $this->assertSame(0, Artisan::call('importBookingJson:bucket', ['--sync' => true]));

        $this->receiveFile(self::PDF, 'pdf', 'pdf');
        $this->receiveFile('SrvOnClickFiles_nobody_names_me', 'png', 'png');
        // An image row cannot take a pdf: the photo keeps waiting for a picture.
        $this->receiveFile(self::PHOTO, 'pdf', 'x');
        // Not read at all: no system prefix.
        $this->bucket()->put('import-files/notes.txt', 'x');
        $this->bucket()->put('import-files/Other_abc.pdf', 'x');

        $this->assertSame(0, Artisan::call('importFile:bucket', ['--sync' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Files received in import-files: 3. Saved now: 1 (copied to files, the received files moved to loaded-files), 1 file / image rows linked.', $output);
        $this->assertStringContainsString('Linked before: 0. Named by a booking but not received yet: 2. Received but not named by a booking yet: 2 (they stay in import-files).', $output);

        $this->assertSame('files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame('files/' . self::DOC, File::where('name', self::DOC)->value('path'));
        $this->assertSame('files/' . self::PHOTO, Image::where('name', self::PHOTO)->value('path'));
        $this->assertSame(['files/' . self::PDF . '.pdf'], $this->bucket()->files('files'));
        $this->assertSame(['loaded-files/' . self::PDF . '.pdf'], $this->bucket()->files('loaded-files'));
        $this->assertEqualsCanonicalizing(
            ['import-files/SrvOnClickFiles_nobody_names_me.png', 'import-files/' . self::PHOTO . '.pdf', 'import-files/notes.txt', 'import-files/Other_abc.pdf'],
            $this->bucket()->files('import-files'),
            'what nobody takes, and what is not ours, stays where it is'
        );
        $this->assertSame(
            ['file_name' => self::PDF . '.pdf', 'stream' => 'attachments', 'source' => 'bucket'],
            DigitalHatchFetchedFile::where('stream', 'attachments')->firstOrFail()->only(['file_name', 'stream', 'source'])
        );

        // Run again: nothing new is saved.
        $this->assertSame(0, Artisan::call('importFile:bucket', ['--sync' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Saved now: 0 (copied to files, the received files moved to loaded-files), 0 file / image rows linked.', $output);
        $this->assertStringContainsString('Linked before: 1. Named by a booking but not received yet: 2.', $output);
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

    /** @return array<string, array{0: string, 1: class-string}> the file commands and the one job each queues */
    public static function fileCommandsAndTheirJobs(): array
    {
        return [
            'importFile:bucket' => ['importFile:bucket', ImportFileBucketJob::class],
            'importFile:ftp' => ['importFile:ftp', ImportFileFtpJob::class],
        ];
    }

    #[DataProvider('fileCommandsAndTheirJobs')]
    public function test_a_file_command_only_queues_its_own_job_without_sync(string $command, string $jobClass): void
    {
        Queue::fake();
        $this->useLocalImportFolders();
        file_put_contents($this->localFilesDir . '/' . self::PDF . '.pdf', 'x');
        $this->receiveFile(self::DOC, 'docx');

        $this->assertSame(0, Artisan::call($command));

        $this->assertStringContainsString(class_basename($jobClass) . ' queued', Artisan::output());
        Queue::assertPushed($jobClass, 1);
        Queue::assertCount(1);

        // Nothing was taken or saved yet: that is the job's work.
        $this->assertSame(0, DigitalHatchFetchedFile::count());
        $this->assertTrue($this->bucket()->exists('import-files/' . self::DOC . '.docx'));
    }

    /** @return array<string, array{0: string, 1: string, 2: class-string}> the json commands, where their file is, and the chunk job each queues */
    public static function jsonCommandsAndTheirChunkJobs(): array
    {
        return [
            'importBookingJson:bucket' => ['importBookingJson:bucket', 'bucket', ImportBookingJsonChunkJob::class],
            'importBookingJson:ftp' => ['importBookingJson:ftp', 'local folder', ImportBookingJsonChunkJob::class],
            'importApplicationLogJson:bucket' => ['importApplicationLogJson:bucket', 'bucket', ImportApplicationLogJsonChunkJob::class],
            'importApplicationLogJson:ftp' => ['importApplicationLogJson:ftp', 'local folder', ImportApplicationLogJsonChunkJob::class],
        ];
    }

    #[DataProvider('jsonCommandsAndTheirChunkJobs')]
    public function test_a_json_command_reads_the_file_itself_and_queues_its_rows_as_chunk_jobs(string $command, string $where, string $chunkJob): void
    {
        Queue::fake();
        $this->useLocalImportFolders();

        $bookings = $chunkJob === ImportBookingJsonChunkJob::class;
        $row = $bookings ? $this->foodBooking(6701) : $this->logRecord(6701, ActionTypeEnum::STORE_BOOKING->value, self::OWNER);

        // The one file the command is to read: in the local folder for ":ftp", in the bucket for ":bucket".
        if ($where === 'bucket') {
            $bookings ? $this->putBookingFile([$row]) : $this->putLogFile([$row]);
        } else {
            $bookings ? $this->putLocalBookings([$row]) : $this->putLocalLogs([$row]);
        }

        $this->assertSame(0, Artisan::call($command));
        $output = Artisan::output();

        // The command did the reading: one chunk job for the one chunk of rows, and the file is backed up in loaded-json already.
        Queue::assertPushed($chunkJob, 1);
        Queue::assertCount(1);
        $this->assertStringContainsString('1 ' . class_basename($chunkJob) . ' jobs queued: a queue worker (php artisan queue:work) saves them', $output);
        $this->assertStringContainsString('1 chunks queued, file moved to loaded-json/', $output);
        $this->assertCount(1, $this->bucket()->files('loaded-json'));
        $this->assertSame([], $this->bucket()->files('import-json'));

        // The file is in the ledger of the digital hatch: taken from the local folder, or imported from the bucket.
        $this->assertSame(
            $where === 'bucket' ? 'bucket' : 'local',
            DigitalHatchFetchedFile::firstOrFail()->source
        );

        // Saving the rows is the worker's job: nothing is in the database until the chunk job runs.
        $this->assertSame(0, Booking::count() + ApplicationLog::count());
    }

    public function test_the_chunk_jobs_a_command_queued_save_the_rows_when_the_worker_runs_them(): void
    {
        Queue::fake();
        $this->useLocalImportFolders();
        $this->putLocalBookings([$this->foodBooking(6801), $this->foodBooking(6802)]);
        $this->putLocalLogs([$this->logRecord(6801, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)]);

        $this->assertSame(0, Artisan::call('importBookingJson:ftp'));
        $this->assertSame(0, Artisan::call('importApplicationLogJson:ftp'));

        // The worker: the bookings first, as they were queued first.
        Queue::pushed(ImportBookingJsonChunkJob::class)->each(fn (ImportBookingJsonChunkJob $job) => $job->handle());
        Queue::pushed(ImportApplicationLogJsonChunkJob::class)->each(fn (ImportApplicationLogJsonChunkJob $job) => $job->handle());

        $this->assertSame(2, Booking::count());
        $this->assertSame(1, ApplicationLog::count());
        $this->assertEqualsCanonicalizing(
            ['loaded-json/' . self::BOOKINGS_FILE_ON_DISK, 'loaded-json/' . self::LOGS_FILE_ON_DISK],
            $this->bucket()->files('loaded-json')
        );
        $this->assertEqualsCanonicalizing([
            ['file_name' => self::BOOKINGS_FILE_ON_DISK, 'stream' => 'export_bookings', 'source' => 'local'],
            ['file_name' => self::LOGS_FILE_ON_DISK, 'stream' => 'application_logs', 'source' => 'local'],
        ], DigitalHatchFetchedFile::get(['file_name', 'stream', 'source'])->toArray());
    }

    public function test_logs_imported_before_their_bookings_wait_in_the_queue_and_are_saved_once_the_bookings_are(): void
    {
        Queue::fake();
        $this->useLocalImportFolders();
        $chunk = [$this->logRecord(7001, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)];
        $this->putLocalLogs($chunk);
        $this->putLocalBookings([$this->foodBooking(7001)]);

        // The logs first, as it happened on the developer's machine: no booking is saved yet.
        $this->assertSame(0, Artisan::call('importApplicationLogJson:ftp'));
        Queue::assertPushed(ImportApplicationLogJsonChunkJob::class, 1);

        // The worker runs the chunk: nothing to save yet, so it is put back to look again later - not dropped.
        $first = (new ImportApplicationLogJsonChunkJob($chunk, 0))->withFakeQueueInteractions();
        $first->handle();
        $first->assertReleased(delay: 60);
        $this->assertSame(0, ApplicationLog::count());

        // The bookings come in; the chunk looks again and saves the log.
        $this->assertSame(0, Artisan::call('importBookingJson:ftp'));
        Queue::pushed(ImportBookingJsonChunkJob::class)->each(fn (ImportBookingJsonChunkJob $job) => $job->handle());

        $second = (new ImportApplicationLogJsonChunkJob($chunk, 0))->withFakeQueueInteractions();
        $second->handle();
        $second->assertNotReleased();
        $this->assertSame(1, ApplicationLog::count());
    }

    public function test_importApplicationLogJson_ftp_sync_keeps_the_file_while_the_bookings_are_not_saved_yet(): void
    {
        $this->useLocalImportFolders();
        $this->putLocalLogs([$this->logRecord(7101, ActionTypeEnum::STORE_BOOKING->value, self::OWNER)]);

        // No booking in the database: nothing can be saved, and the file must not be put away as if it were.
        $this->assertSame(1, Artisan::call('importApplicationLogJson:ftp', ['--sync' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('1 logs have no booking saved yet: import the bookings first and run this again', $output);
        $this->assertStringContainsString('file left in place because of errors', $output);
        $this->assertSame(0, ApplicationLog::count());
        $this->assertSame(['import-json/' . self::LOGS_FILE_ON_DISK], $this->bucket()->files('import-json'));
        $this->assertSame([], $this->bucket()->files('loaded-json'));
        $this->assertSame(1, DigitalHatchFetchedFile::count(), 'it was taken from the folder, so it is not taken again');

        // The bookings come in; the same command finds the file that waits (nothing new to take) and saves the log.
        $this->putLocalBookings([$this->foodBooking(7101)]);
        $this->assertSame(0, Artisan::call('importBookingJson:ftp', ['--sync' => true]));
        $this->assertSame(0, Artisan::call('importApplicationLogJson:ftp', ['--sync' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('No new file in the source', $output);
        $this->assertStringContainsString('1 logs in 1 chunks saved (1 created), file moved to loaded-json/', $output);
        $this->assertSame(1, ApplicationLog::count());
        $this->assertSame([], $this->bucket()->files('import-json'));
    }

    public function test_force_is_handed_to_the_ftp_file_job_and_only_the_ftp_commands_take_it(): void
    {
        Queue::fake();

        Artisan::call('importFile:ftp', ['--force' => true]);
        Queue::assertPushed(ImportFileFtpJob::class, fn (ImportFileFtpJob $job): bool => $job->force === true);

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

    public function test_without_sync_the_rows_go_through_the_queue_and_the_worker_saves_them(): void
    {
        $this->useLocalImportFolders();
        $this->putLocalBookings([$this->foodBooking(6901)]);

        // The test queue is sync, so each queued chunk job runs at once, the way a worker would run it.
        $this->assertSame(0, Artisan::call('importBookingJson:ftp'));
        $output = Artisan::output();

        $this->assertStringContainsString('1 bookings in 1 chunks queued, file moved to loaded-json/', $output);
        $this->assertStringContainsString('1 ImportBookingJsonChunkJob jobs queued', $output);
        $this->assertTrue(Booking::where('serial_number', 6901)->exists());
        Storage::disk('minio')->assertExists('loaded-json/' . self::BOOKINGS_FILE_ON_DISK);
        $this->assertSame(1, DigitalHatchFetchedFile::count());
    }

    public function test_a_json_command_fails_and_leaves_the_file_when_it_cannot_be_read_even_without_sync(): void
    {
        Storage::disk('minio')->put(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE, 'not json');

        $this->assertSame(1, Artisan::call('importBookingJson:bucket'));
        $this->assertStringContainsString(self::BOOKINGS_FILE, Artisan::output());
        Storage::disk('minio')->assertExists(self::BOOKING_DIRECTORY . '/' . self::BOOKINGS_FILE);
        $this->assertSame(0, DigitalHatchFetchedFile::count(), 'a file that was not imported is not noted as taken');
    }

    public function test_an_ftp_job_throws_while_the_ftp_cannot_be_reached(): void
    {
        $this->useUnreachableFtp();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('listing');

        (new ImportFileFtpJob())->handle();
    }

    public function test_the_file_jobs_take_their_settings_from_the_config(): void
    {
        config(['app.jobs.import_job' => ['tries' => 7, 'backoff' => 13, 'timeout' => 99]]);

        foreach ([new ImportFileBucketJob(), new ImportFileFtpJob(true)] as $job) {
            $this->assertSame([7, 13, 99], [$job->tries, $job->backoff, $job->timeout], $job::class);
        }
    }

    public function test_the_chunk_jobs_take_their_settings_from_their_own_config(): void
    {
        config([
            'app.jobs.import_booking_json' => ['tries' => 2, 'backoff' => 3, 'timeout' => 4],
            'app.jobs.import_application_log_json' => ['tries' => 5, 'backoff' => 6, 'timeout' => 7],
        ]);

        $booking = new ImportBookingJsonChunkJob([], 0);
        $log = new ImportApplicationLogJsonChunkJob([], 0);

        $this->assertSame([2, 3, 4], [$booking->tries, $booking->backoff, $booking->timeout]);
        $this->assertSame([5, 6, 7], [$log->tries, $log->backoff, $log->timeout]);
    }

    //----------------------------- The schedule ------------------------------------//

    /** @return \Illuminate\Support\Collection<int, \Illuminate\Console\Scheduling\Event> */
    private function scheduled(): \Illuminate\Support\Collection
    {
        Artisan::call('schedule:list');

        return collect(app(Schedule::class)->events());
    }

    public function test_the_ftp_imports_are_scheduled_in_order_bookings_then_logs_then_files_and_the_fetch_only_commands_are_gone(): void
    {
        $find = fn (string $name) => $this->scheduled()->first(fn ($event) => str_contains($event->command, $name));

        // A log and a file belong to a booking, so they come after the bookings.
        $this->assertSame('2-59/5 * * * *', $find('importBookingJson:ftp')->expression);
        $this->assertSame('3-59/5 * * * *', $find('importApplicationLogJson:ftp')->expression);
        $this->assertSame('4-59/5 * * * *', $find('importFile:ftp')->expression);

        $this->assertNull($find('digital-hatch:fetch'), 'the :ftp imports take the files themselves');
    }

    public function test_the_bucket_imports_are_scheduled_in_the_same_order(): void
    {
        $find = fn (string $name) => $this->scheduled()->first(fn ($event) => str_contains($event->command, $name));

        $this->assertSame('*/5 * * * *', $find('importBookingJson:bucket')?->expression, 'importBookingJson:bucket is not in routes/console.php');
        $this->assertSame('1-59/5 * * * *', $find('importApplicationLogJson:bucket')?->expression, 'importApplicationLogJson:bucket is not in routes/console.php');
        $this->assertSame('4-59/5 * * * *', $find('importFile:bucket')?->expression, 'importFile:bucket is not in routes/console.php');
    }
}

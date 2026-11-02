<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DigitalHatchFetchedFile;
use App\Models\File;
use App\Models\Image;
use App\Services\AttachmentService;
use App\Services\ImportBookingService;
use App\Services\ImportFileService;
use App\Services\StorageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/**
 * The files and images of the bookings: the booking json names them (the stored name, no extension) and the bytes wait in import-files as
 * SrvOnClickFiles_<stored name>.<ext>. Saving them links each file / image row to its bytes, copies the bytes to files (where the bookings serve
 * them from) and moves the received file to loaded-files, a backup like loaded-json.
 */
class ImportFileTest extends ImportJsonTestCase
{
    // The names of the real export: a booking with one file (a pdf) and a booking with two images (png).
    private const PDF = 'SrvOnClickFiles_6ae8988ed5a05_R4kko0eXXh';
    private const PNG_1 = 'SrvOnClickFiles_6ae898b4c9256_k99ztnz1be';
    private const PNG_2 = 'SrvOnClickFiles_6ae898b4ea510_MnnzANkQpV';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function importFiles(): array
    {
        return app(ImportFileService::class)->importFromBucket();
    }

    /** The two bookings of the real export: an equipment booking with a file and a construction booking with two images. */
    private function putBookings(): void
    {
        $this->putBookingFile([
            $this->bookingRecord(277946, 3, [], ['files' => [['file_id' => 1, 'path' => self::PDF]]]),
            $this->bookingRecord(729947, 13, [], ['images' => [['image_id' => 1, 'path' => self::PNG_1], ['image_id' => 2, 'path' => self::PNG_2]]]),
        ]);
        app(ImportBookingService::class)->importFromBucket(sync: true);
    }

    private function receiveAll(): void
    {
        $this->receiveFile(self::PDF, 'pdf', '%PDF-1.4 the form');
        $this->receiveFile(self::PNG_1, 'png', 'PNG-ONE');
        $this->receiveFile(self::PNG_2, 'png', 'PNG-TWO');
    }

    public function test_the_received_files_a_booking_names_are_linked_copied_to_files_and_moved_to_loaded_files(): void
    {
        $this->putBookings();
        $this->receiveAll();

        $result = $this->importFiles();

        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['in_bucket']);
        $this->assertSame(3, $result['moved']);
        $this->assertSame(3, $result['linked']);
        $this->assertSame(0, $result['unclaimed']);
        $this->assertSame(0, $result['waiting']);

        // The rows know their bytes: the copy in files, extension included.
        $this->assertSame('files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame(self::PDF . '.pdf', File::where('name', self::PDF)->value('file_name'));
        $this->assertSame('files/' . self::PNG_1 . '.png', Image::where('name', self::PNG_1)->value('path'));
        $this->assertSame('files/' . self::PNG_2 . '.png', Image::where('name', self::PNG_2)->value('path'));

        // All files and images together in files, the received ones moved to loaded-files, the inbox empty.
        $this->assertEqualsCanonicalizing(
            ['files/' . self::PDF . '.pdf', 'files/' . self::PNG_1 . '.png', 'files/' . self::PNG_2 . '.png'],
            Storage::disk('minio')->files('files')
        );
        $this->assertEqualsCanonicalizing(
            ['loaded-files/' . self::PDF . '.pdf', 'loaded-files/' . self::PNG_1 . '.png', 'loaded-files/' . self::PNG_2 . '.png'],
            Storage::disk('minio')->files('loaded-files')
        );
        $this->assertSame([], Storage::disk('minio')->files('import-files'));
        $this->assertSame('%PDF-1.4 the form', Storage::disk('minio')->get('files/' . self::PDF . '.pdf'));
        $this->assertSame('PNG-TWO', Storage::disk('minio')->get('loaded-files/' . self::PNG_2 . '.png'));

        // The ledger notes each file as taken from the bucket (and the booking file that named them, imported before).
        $this->assertEqualsCanonicalizing([
            ['file_name' => self::PDF . '.pdf', 'stream' => 'attachments', 'source' => 'bucket'],
            ['file_name' => self::PNG_1 . '.png', 'stream' => 'attachments', 'source' => 'bucket'],
            ['file_name' => self::PNG_2 . '.png', 'stream' => 'attachments', 'source' => 'bucket'],
        ], DigitalHatchFetchedFile::where('stream', 'attachments')->get(['file_name', 'stream', 'source'])->toArray());
        $this->assertSame(
            [['file_name' => self::BOOKINGS_FILE, 'stream' => 'export_bookings', 'source' => 'bucket']],
            DigitalHatchFetchedFile::where('stream', 'export_bookings')->get(['file_name', 'stream', 'source'])->toArray()
        );
    }

    public function test_the_saved_files_are_the_ones_the_bookings_serve(): void
    {
        $this->putBookings();
        $this->receiveAll();
        $this->importFiles();

        $booking = Booking::where('serial_number', 277946)->firstOrFail();
        $described = app(AttachmentService::class)->describe($booking->load('files', 'images'));

        $this->assertCount(1, $described);
        $this->assertTrue($described[0]['available']);
        $this->assertSame('application/pdf', $described[0]['mime']);
    }

    public function test_reading_again_saves_nothing_twice_and_a_file_delivered_again_is_kept_under_a_new_name(): void
    {
        Carbon::setTestNow('2026-11-02 16:12:00');
        $this->putBookings();
        $this->receiveAll();
        $this->importFiles();

        // Nothing is waiting: nothing changes.
        $again = $this->importFiles();
        $this->assertSame(0, $again['in_bucket']);
        $this->assertSame(0, $again['moved']);
        $this->assertSame(3, $again['already_linked']);

        // The mobile side delivers the same file again: the copy in files is replaced, the backup keeps both.
        $this->receiveFile(self::PDF, 'pdf', 'NEW BYTES');
        $third = $this->importFiles();

        $this->assertSame(1, $third['moved']);
        $this->assertSame(0, $third['linked'], 'the row already pointed there');
        $this->assertSame('NEW BYTES', Storage::disk('minio')->get('files/' . self::PDF . '.pdf'));
        $this->assertSame('%PDF-1.4 the form', Storage::disk('minio')->get('loaded-files/' . self::PDF . '.pdf'));
        $this->assertSame('NEW BYTES', Storage::disk('minio')->get('loaded-files/' . self::PDF . '_2026-11-02_16-12-00.pdf'));
        $this->assertSame(3, File::count() + Image::count());
    }

    public function test_a_received_file_waits_in_the_inbox_until_the_booking_that_names_it_is_saved(): void
    {
        $this->receiveAll();

        $first = $this->importFiles();

        $this->assertSame(3, $first['in_bucket']);
        $this->assertSame(0, $first['moved']);
        $this->assertSame(3, $first['unclaimed']);
        $this->assertCount(3, Storage::disk('minio')->files('import-files'));
        $this->assertSame([], Storage::disk('minio')->files('files'));

        $this->putBookings();
        $second = $this->importFiles();

        $this->assertSame(3, $second['moved']);
        $this->assertSame(0, $second['unclaimed']);
        $this->assertSame([], Storage::disk('minio')->files('import-files'));
    }

    public function test_a_row_whose_bytes_have_not_arrived_waits_and_is_named(): void
    {
        $this->putBookings();
        $this->receiveFile(self::PNG_1, 'png');

        $result = $this->importFiles();

        $this->assertSame(1, $result['moved']);
        $this->assertSame(2, $result['waiting']);
        $this->assertEqualsCanonicalizing([self::PDF, self::PNG_2], $result['waiting_names']);
        // The key it will have, without an extension until the bytes come.
        $this->assertSame('files/' . self::PDF, File::where('name', self::PDF)->value('path'));
    }

    public function test_an_image_does_not_take_a_pdf_and_that_file_stays_in_the_inbox(): void
    {
        $this->putBookings();
        $this->receiveFile(self::PNG_1, 'pdf', 'not a picture');

        $result = $this->importFiles();

        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, $result['unclaimed']);
        $this->assertSame([self::PNG_1 . '.pdf'], $result['unclaimed_names']);
        $this->assertSame('files/' . self::PNG_1, Image::where('name', self::PNG_1)->value('path'));
        $this->assertSame(['import-files/' . self::PNG_1 . '.pdf'], Storage::disk('minio')->files('import-files'));
    }

    public function test_only_the_system_prefix_and_the_types_that_are_served_are_read(): void
    {
        $this->putBookings();
        $this->receiveAll();

        Storage::disk('minio')->put('import-files/Other_abc.pdf', 'x');
        Storage::disk('minio')->put('import-files/srvonclickfiles_abc.pdf', 'x');
        Storage::disk('minio')->put('import-files/notes.txt', 'x');
        Storage::disk('minio')->put('import-files/' . self::PDF . '.exe', 'MZ');
        Storage::disk('minio')->put('import-files/SrvOnClickFiles_with space.pdf', 'x');

        $result = $this->importFiles();

        $this->assertSame(3, $result['in_bucket'], 'the others are not even counted');
        $this->assertSame(3, $result['moved']);
        $this->assertEqualsCanonicalizing(
            ['import-files/Other_abc.pdf', 'import-files/srvonclickfiles_abc.pdf', 'import-files/notes.txt', 'import-files/' . self::PDF . '.exe', 'import-files/SrvOnClickFiles_with space.pdf'],
            Storage::disk('minio')->files('import-files'),
            'what is not ours is left exactly where it is'
        );
    }

    public function test_a_row_saved_before_with_the_old_place_is_pointed_at_files(): void
    {
        $this->putBookings();
        // Before: the bytes were read straight from the inbox and the row kept that key.
        File::where('name', self::PDF)->update(['path' => 'import-files/' . self::PDF . '.pdf', 'file_name' => self::PDF . '.pdf']);
        $this->receiveFile(self::PDF, 'pdf', 'OLD PLACE');

        $result = $this->importFiles();

        $this->assertSame(1, $result['moved']);
        $this->assertSame(1, $result['linked']);
        $this->assertSame('files/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        $this->assertSame('OLD PLACE', Storage::disk('minio')->get('files/' . self::PDF . '.pdf'));
    }

    public function test_a_copy_that_fails_leaves_the_rows_and_the_received_file_as_they_were(): void
    {
        $this->putBookings();
        $this->receiveAll();

        $this->partialMock(StorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('copyBucketFile')->andThrow(new \RuntimeException('bucket full'));
        });

        $result = app(ImportFileService::class)->importFromBucket();

        $this->assertSame(0, $result['moved']);
        $this->assertCount(3, $result['errors']);
        $this->assertStringContainsString('bucket full', $result['errors'][0]);
        $this->assertSame('files/' . self::PDF, File::where('name', self::PDF)->value('path'), 'the row does not point at bytes that are not there');
        $this->assertCount(3, Storage::disk('minio')->files('import-files'));
        $this->assertSame(0, DigitalHatchFetchedFile::where('stream', 'attachments')->count(), 'nothing was saved, so nothing is noted');
    }

    public function test_the_folders_and_the_bucket_come_from_the_config(): void
    {
        config([
            'app.prod_stage' => false,
            'filesystems.disks.minio.bucket' => 'srvonclick-bucket',
            'filesystems.folder_name.import_files' => 'inbox-x',
            'filesystems.folder_name.attachments' => 'live-x',
            'filesystems.folder_name.loaded_files' => 'done-x',
        ]);

        // Outside prod the bucket name is part of every key.
        Storage::disk('minio')->put('srvonclick-bucket/import-json/SrvOnClick_b2r_export_bookings_2026-11-02_16-07-36.json', json_encode(['bookings' => [
            $this->bookingRecord(277946, 3, [], ['files' => [['file_id' => 1, 'path' => self::PDF]]]),
        ]]));
        app(ImportBookingService::class)->importFromBucket(sync: true);
        Storage::disk('minio')->put('srvonclick-bucket/inbox-x/' . self::PDF . '.pdf', 'bytes');

        $result = $this->importFiles();

        $this->assertSame(1, $result['moved']);
        $this->assertSame('srvonclick-bucket/live-x/' . self::PDF . '.pdf', File::where('name', self::PDF)->value('path'));
        Storage::disk('minio')->assertExists('srvonclick-bucket/live-x/' . self::PDF . '.pdf');
        Storage::disk('minio')->assertExists('srvonclick-bucket/done-x/' . self::PDF . '.pdf');
        Storage::disk('minio')->assertMissing('srvonclick-bucket/inbox-x/' . self::PDF . '.pdf');
    }
}

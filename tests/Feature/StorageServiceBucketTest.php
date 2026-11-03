<?php

namespace Tests\Feature;

use App\Services\StorageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The bucket helpers the imports use, on a bucket that is a folder in memory. */
class StorageServiceBucketTest extends TestCase
{
    private StorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');

        config([
            'filesystems.storage_service' => 'minio',
            'filesystems.disks.minio.bucket' => 'srvonclick-bucket',
            'app.prod_stage' => true,
            'logging.default' => 'null',
        ]);

        $this->storage = new StorageService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_bucket_name_is_part_of_the_object_key_only_outside_prod(): void
    {
        $this->assertSame('import-json/booking-data/a.json', $this->storage->buildBucketKey('import-json/booking-data', 'a.json'));
        $this->assertSame('import-json/booking-data', $this->storage->buildBucketKey('/import-json/booking-data/'));

        config(['app.prod_stage' => false]);

        $this->assertSame('srvonclick-bucket/import-json/booking-data/a.json', $this->storage->buildBucketKey('import-json/booking-data', 'a.json'));
    }

    public function test_it_lists_the_json_files_with_the_prefix_oldest_name_first(): void
    {
        foreach ([
            'SrvOnClick_b2r_export_bookings_2026-10-30.json',
            'SrvOnClick_b2r_export_bookings_2026-10-28_10-10-35.json',
            'SrvOnClick_b2r_export_bookings_2026-10-29.JSON',
            'SrvOnClick_b2r_export_bookings_2026-10-29.txt',
            'SrvOnClick_b2r_application_logs_2026-10-29.json',
            'notes.json',
        ] as $name) {
            Storage::disk('minio')->put('import-json/booking-data/' . $name, '{}');
        }
        Storage::disk('minio')->put('elsewhere/SrvOnClick_b2r_export_bookings_2026-10-01.json', '{}');

        $this->assertSame([
            'SrvOnClick_b2r_export_bookings_2026-10-28_10-10-35.json',
            'SrvOnClick_b2r_export_bookings_2026-10-29.JSON',
            'SrvOnClick_b2r_export_bookings_2026-10-30.json',
        ], $this->storage->listJsonFilesInBucket('SrvOnClick_b2r_export_bookings_', 'import-json/booking-data'));

        $this->assertSame([], $this->storage->listJsonFilesInBucket('SrvOnClick_b2r_export_bookings_', 'a-folder-that-is-not-there'));
    }

    public function test_it_reads_a_json_file_and_drops_the_byte_order_mark(): void
    {
        Storage::disk('minio')->put('dir/plain.json', '{"bookings": [{"serial_number": 1}]}');
        Storage::disk('minio')->put('dir/bom.json', "\xEF\xBB\xBF" . '{"bookings": []}');

        $this->assertSame(['bookings' => [['serial_number' => 1]]], $this->storage->readJsonFromBucket('plain.json', 'dir'));
        $this->assertSame(['bookings' => []], $this->storage->readJsonFromBucket('bom.json', 'dir'));
    }

    public function test_it_refuses_a_file_that_is_missing_empty_or_not_json_with_something_in_it(): void
    {
        Storage::disk('minio')->put('dir/empty.json', '');
        Storage::disk('minio')->put('dir/blank.json', "  \n ");
        Storage::disk('minio')->put('dir/cut.json', '{"bookings": [');
        Storage::disk('minio')->put('dir/number.json', '5');
        Storage::disk('minio')->put('dir/null.json', 'null');

        foreach (['missing.json' => 'not found', 'empty.json' => 'is empty', 'blank.json' => 'is empty', 'cut.json' => 'Syntax error', 'number.json' => 'not a JSON object', 'null.json' => 'not a JSON object'] as $name => $why) {
            try {
                $this->storage->readJsonFromBucket($name, 'dir');
                $this->fail("$name should have been refused");
            } catch (\Exception $e) {
                $this->assertStringContainsString($why, $e->getMessage(), $name);
            }
        }
    }

    public function test_it_moves_a_file_and_keeps_one_that_is_already_there(): void
    {
        Carbon::setTestNow('2026-10-29 10:00:00');

        Storage::disk('minio')->put('inbox/a.json', 'first');
        $this->assertSame('a.json', $this->storage->moveBucketFile('a.json', 'inbox', 'loaded'));
        Storage::disk('minio')->assertMissing('inbox/a.json');
        $this->assertSame('first', Storage::disk('minio')->get('loaded/a.json'));

        // The same name again: the new one gets the time it was loaded, the old one stays as it was.
        Storage::disk('minio')->put('inbox/a.json', 'second');
        $this->assertSame('a_2026-10-29_10-00-00.json', $this->storage->moveBucketFile('a.json', 'inbox', 'loaded'));

        // And once more in the same second.
        Storage::disk('minio')->put('inbox/a.json', 'third');
        $this->assertSame('a_2026-10-29_10-00-00_2.json', $this->storage->moveBucketFile('a.json', 'inbox', 'loaded'));

        $this->assertSame('first', Storage::disk('minio')->get('loaded/a.json'));
        $this->assertSame('second', Storage::disk('minio')->get('loaded/a_2026-10-29_10-00-00.json'));
        $this->assertSame('third', Storage::disk('minio')->get('loaded/a_2026-10-29_10-00-00_2.json'));
        $this->assertSame([], Storage::disk('minio')->files('inbox'));
    }

    public function test_it_will_not_move_a_file_that_is_not_there(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found in the bucket');

        $this->storage->moveBucketFile('nothing.json', 'inbox', 'loaded');
    }

    public function test_it_keeps_a_local_file_in_a_folder_without_replacing_one_that_is_there(): void
    {
        $local = tempnam(sys_get_temp_dir(), 'put_');

        try {
            file_put_contents($local, 'first');
            $this->assertSame('export-report/a.xlsx', $this->storage->putLocalFileToBucket($local, 'a.xlsx', 'export-report'));

            // The same name again: the new file takes the next free one and the old one stays as it was.
            file_put_contents($local, 'second');
            $this->assertSame('export-report/a_2.xlsx', $this->storage->putLocalFileToBucket($local, 'a.xlsx', '/export-report/'));

            file_put_contents($local, 'third');
            $this->assertSame('export-report/a_3.xlsx', $this->storage->putLocalFileToBucket($local, 'a.xlsx', 'export-report'));

            $this->assertSame('first', Storage::disk('minio')->get('export-report/a.xlsx'));
            $this->assertSame('second', Storage::disk('minio')->get('export-report/a_2.xlsx'));
            $this->assertSame('third', Storage::disk('minio')->get('export-report/a_3.xlsx'));

            // A name without an extension, and outside prod the bucket name is part of the key.
            config(['app.prod_stage' => false]);
            $this->assertSame('srvonclick-bucket/export-report/b', $this->storage->putLocalFileToBucket($local, 'b', 'export-report'));
            $this->assertSame('srvonclick-bucket/export-report/b_2', $this->storage->putLocalFileToBucket($local, 'b', 'export-report'));
        } finally {
            @unlink($local);
        }
    }

    public function test_it_will_not_keep_a_local_file_that_cannot_be_read(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read');

        // fopen() warns about the missing file; the test turns it into the exception being checked, not a failure.
        set_error_handler(static fn (): bool => true);

        try {
            $this->storage->putLocalFileToBucket(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'no_such_file_' . uniqid(), 'a.xlsx', 'export-report');
        } finally {
            restore_error_handler();
        }
    }
}

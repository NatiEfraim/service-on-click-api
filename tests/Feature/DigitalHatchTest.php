<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DigitalHatchFetchedFile;
use App\Models\File;
use App\Services\AttachmentService;
use App\Services\DigitalHatchService;
use App\Services\ImportBookingService;

/**
 * The b2r side of the digital hatch: what the mobile side puts on the FTP is taken into the bucket folders the importers read.
 * (The r2b side, one stream at a time, is in DigitalHatchSendTest.)
 */
class DigitalHatchTest extends HatchTestCase
{
    private const BOOKINGS_ON_FTP = 'SrvOnClick_b2r_export_bookings_2026-11-02_08-54-11.json';
    private const LOGS_ON_FTP = 'SrvOnClick_b2r_application_logs_2026-11-02_08-54-17.json';

    private const PDF = 'SrvOnClickFiles_6adf010810404_j0iNc8FqHf';
    private const DOC = 'SrvOnClickFiles_0b3f8e2a-5c1d-4e7f-9a62-3d8c41f7b9e0';

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

    public function test_nothing_is_fetched_while_the_hatch_is_disabled(): void
    {
        config(['services.digital-hatch.enabled' => false]);

        $this->ftp()->put(self::BLACK_TO_RED . self::BOOKINGS_ON_FTP, json_encode(['bookings' => []]));
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', 'x');

        $this->assertSame(['enabled' => false], app(DigitalHatchService::class)->fetch());
        $this->assertSame(['enabled' => false], app(DigitalHatchService::class)->fetchAttachments());
        $this->assertSame([], $this->bucket()->allFiles());

        $this->artisan('digital-hatch:fetch')->expectsOutputToContain('disabled')->assertExitCode(0);
        $this->artisan('digital-hatch:fetch-attachments')->expectsOutputToContain('disabled')->assertExitCode(0);
    }

    public function test_the_fetch_commands_report_what_they_moved(): void
    {
        $this->ftp()->put(self::BLACK_TO_RED . self::LOGS_ON_FTP, json_encode(['application_logs' => []]));
        $this->ftp()->put(self::ATTACHMENTS . self::PDF . '.pdf', 'x');

        $this->artisan('digital-hatch:fetch')->expectsOutputToContain('Fetched application_logs: ' . self::LOGS_ON_FTP)->assertExitCode(0);
        $this->artisan('digital-hatch:fetch-attachments')->expectsOutputToContain('Fetched ' . self::PDF . '.pdf')->assertExitCode(0);

        $this->artisan('digital-hatch:fetch')->expectsOutputToContain('No new file on the FTP.')->assertExitCode(0);
        $this->artisan('digital-hatch:fetch-attachments')->expectsOutputToContain('No new attachment on the FTP.')->assertExitCode(0);
    }

    public function test_an_unreachable_ftp_fails_the_fetch_instead_of_looking_like_an_empty_folder(): void
    {
        $this->useUnreachableFtp();

        $this->assertNotSame([], app(DigitalHatchService::class)->fetch()['errors']);
        $this->assertNotSame([], app(DigitalHatchService::class)->fetchAttachments()['errors']);

        $this->artisan('digital-hatch:fetch')->assertExitCode(1);
    }
}

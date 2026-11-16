<?php

namespace Tests\Feature;

use App\Enums\HttpStatusCodeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Status;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

/** The bookings report of the super admin and the admin: the filtered list, and the Excel file that is sent back and kept in the bucket. */
class BookingReportTest extends ManagementTestCase
{
    private const URL = '/api/bookings-admin-management/bookings-report';

    private const FOLDER = 'export-report';

    private const HEADINGS = ['מספר קריאה', 'תחום', 'סטטוס', 'שם המבקש', 'תעודת זהות', 'מספר אישי', 'יחידה', 'נוצרה', 'סטטוס עודכן'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('minio');

        config([
            'filesystems.storage_service' => 'minio',
            'filesystems.disks.minio.bucket' => 'srvonclick-bucket',
            'app.prod_stage' => true,
            'app.export_report.timezone' => 'Asia/Jerusalem',
            'logging.default' => 'null',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** A booking of a plain user created at an exact UTC time (created_at is not mass-assignable, and the model prints it as a date). */
    private function bookingAt(string $createdAtUtc, array $attributes = []): Booking
    {
        static $serial = 810000000;

        $booking = Booking::create($attributes + [
            'serial_number' => ++$serial,
            'unit_id' => 1,
            'booking_type_id' => 1,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => 1,
            'ext_status_id' => 1,
            'status_source' => 'external',
            'status_changed_at' => $createdAtUtc,
            'version' => 1,
        ]);

        DB::table('bookings')->where('id', $booking->id)->update(['created_at' => $createdAtUtc, 'updated_at' => $createdAtUtc]);

        return Booking::findOrFail($booking->id);
    }

    /** @return array<int, int> the serial numbers of the rows of a list, in order */
    private function serials(TestResponse $response): array
    {
        return array_map('intval', array_column($response->json('data'), 'serial_number'));
    }

    /** What the browser receives: the file is read the way the server sends it, which also removes the temporary file. */
    private function sentBytes(TestResponse $response): string
    {
        $path = $response->baseResponse->getFile()->getPathname();

        ob_start();
        $response->baseResponse->sendContent();
        $bytes = (string) ob_get_clean();

        $this->assertFileDoesNotExist($path, 'the temporary file is deleted once it is sent');

        return $bytes;
    }

    /** @return array{name: string, rows: array<int, array<int, mixed>>} the first sheet; a date comes as "Y-m-d H:i" */
    private function readWorkbook(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'report_test_');
        file_put_contents($path, $bytes);

        $reader = new Reader();
        $rows = [];
        $name = '';

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $sheet->getName();

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : $value, $row->toArray());
                }

                break;
            }
        } finally {
            $reader->close();
            @unlink($path);
        }

        return ['name' => $name, 'rows' => $rows];
    }

    private function sheetXml(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report_test_');
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    /** An admin of the given booking types only, in unit H. */
    private function adminOf(string $personalNumber, array $bookingTypeIds): User
    {
        $user = User::factory()->create(['personal_number' => $personalNumber, 'unit_id' => 1]);
        $user->assignRole(RoleEnum::ADMIN->value);
        $user->givePermissionTo(array_map(fn (int $id): string => PermissionEnum::ADMIN->keyForId($id), $bookingTypeIds));

        return $user;
    }

    //------------------------------- The list --------------------------------//

    public function test_the_super_admin_gets_every_booking_newest_first(): void
    {
        $this->bookingAt('2025-03-10 08:15:00', ['booking_type_id' => 2, 'status_id' => 3]);

        $response = $this->as(self::SUPER_ADMIN)->getJson(self::URL)->assertOk();

        $this->assertSame(Booking::count(), $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.page'));
        $this->assertSame(25, $response->json('meta.per_page'));

        $dates = array_column($response->json('data'), 'created_at');
        $newestFirst = $dates;
        rsort($newestFirst);
        $this->assertSame($newestFirst, $dates);
    }

    public function test_a_row_tells_the_booking_its_owner_its_unit_and_its_dates(): void
    {
        $booking = $this->bookingAt('2025-03-10 08:15:00', ['booking_type_id' => 2, 'status_id' => 3, 'status_changed_at' => '2025-03-11 09:30:00']);
        $owner = User::where('personal_number', self::PLAIN_USER)->first();

        $row = $this->as(self::SUPER_ADMIN)->getJson(self::URL . '?from_created_at=2025-03-10&to_created_at=2025-03-10')->assertOk()->json('data.0');

        $this->assertSame([
            'uuid' => $booking->uuid,
            'serial_number' => (int) $booking->serial_number,
            'booking_type_id' => 2,
            'booking_type_label' => BookingType::find(2)->display_name,
            'status_id' => 3,
            'status_label' => Status::find(3)->display_name,
            'unit_id' => 1,
            'unit_name' => Unit::find(1)->displayName(),
            'owner_name' => $owner->full_name,
            'owner_personal_id' => null,
            'owner_personal_number' => self::PLAIN_USER,
            'created_at' => '2025-03-10T08:15:00+00:00',
            'status_changed_at' => '2025-03-11T09:30:00+00:00',
        ], $row);
    }

    public function test_the_list_comes_in_pages_of_at_most_100(): void
    {
        $total = Booking::count();

        $page = $this->as(self::SUPER_ADMIN)->getJson(self::URL . '?per_page=2&page=2')->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(2, $page->json('meta.page'));
        $this->assertSame((int) ceil($total / 2), $page->json('meta.last_page'));

        $this->assertSame(100, $this->as(self::SUPER_ADMIN)->getJson(self::URL . '?per_page=1000')->json('meta.per_page'));
    }

    public function test_the_filters_work_alone_and_together(): void
    {
        $a = $this->bookingAt('2025-06-10 09:00:00', ['booking_type_id' => 1, 'status_id' => 1]);
        $b = $this->bookingAt('2025-06-15 09:00:00', ['booking_type_id' => 1, 'status_id' => 3]);
        $c = $this->bookingAt('2025-06-20 09:00:00', ['booking_type_id' => 2, 'status_id' => 1]);
        $d = $this->bookingAt('2025-07-05 09:00:00', ['booking_type_id' => 2, 'status_id' => 3]);
        $total = Booking::count();

        $get = fn (string $query): TestResponse => $this->as(self::SUPER_ADMIN)->getJson(self::URL . '?per_page=100&' . $query)->assertOk();

        // The booking type alone.
        $byType = $get('booking_type_id=2');
        $this->assertSame(Booking::where('booking_type_id', 2)->count(), $byType->json('meta.total'));
        $this->assertSame([2], array_values(array_unique(array_column($byType->json('data'), 'booking_type_id'))));
        $this->assertContains($c->serial_number, $this->serials($byType));
        $this->assertNotContains($a->serial_number, $this->serials($byType));

        // The status alone.
        $byStatus = $get('status_id=3');
        $this->assertSame(Booking::where('status_id', 3)->count(), $byStatus->json('meta.total'));
        $this->assertSame([3], array_values(array_unique(array_column($byStatus->json('data'), 'status_id'))));

        // The days alone: both ends included, newest first.
        $this->assertSame([$c->serial_number, $b->serial_number, $a->serial_number], $this->serials($get('from_created_at=2025-06-01&to_created_at=2025-06-30')));
        $this->assertSame([$d->serial_number, $c->serial_number], $this->serials($get('from_created_at=2025-06-20&to_created_at=2025-07-05')));
        $this->assertSame([$b->serial_number, $a->serial_number], $this->serials($get('to_created_at=2025-06-15')));
        $this->assertSame($total - 2, $get('from_created_at=2025-06-16')->json('meta.total'));

        // All of them at once.
        $this->assertSame([$c->serial_number], $this->serials($get('booking_type_id=2&status_id=1&from_created_at=2025-06-01&to_created_at=2025-06-30')));
        $this->assertSame([$d->serial_number], $this->serials($get('booking_type_id=2&status_id=3&from_created_at=2025-06-01&to_created_at=2025-07-31')));

        // Nothing matches.
        $none = $get('booking_type_id=1&status_id=3&from_created_at=2025-06-01&to_created_at=2025-06-10');
        $this->assertSame([], $none->json('data'));
        $this->assertSame(0, $none->json('meta.total'));
    }

    public function test_the_days_are_counted_in_the_report_timezone(): void
    {
        // Israel is two hours ahead of UTC in winter: its January 1st runs from 22:00 UTC on December 31st to 21:59:59 UTC on January 1st.
        $beforeMidnight = $this->bookingAt('2025-12-31 21:59:59');
        $atMidnight = $this->bookingAt('2025-12-31 22:00:00');
        $endOfDay = $this->bookingAt('2026-01-01 21:59:59');
        $nextDay = $this->bookingAt('2026-01-01 22:00:00');

        $response = $this->as(self::SUPER_ADMIN)->getJson(self::URL . '?from_created_at=2026-01-01&to_created_at=2026-01-01')->assertOk();

        $this->assertSame([$endOfDay->serial_number, $atMidnight->serial_number], $this->serials($response));
        $this->assertNotContains($beforeMidnight->serial_number, $this->serials($response));
        $this->assertNotContains($nextDay->serial_number, $this->serials($response));
    }

    public function test_an_admin_reports_only_on_their_unit_and_the_booking_types_they_administer(): void
    {
        $elsewhere = $this->bookingAt('2025-04-01 10:00:00', ['unit_id' => 2, 'booking_type_id' => 1]);
        $typeOne = $this->bookingAt('2025-04-02 10:00:00', ['booking_type_id' => 1]);
        $typeTwo = $this->bookingAt('2025-04-03 10:00:00', ['booking_type_id' => 2]);
        $april = '?from_created_at=2025-04-01&to_created_at=2025-04-30';

        // The seeded admin holds every type of unit H: nothing from the other unit.
        $this->assertSame([$typeTwo->serial_number, $typeOne->serial_number], $this->serials($this->as(self::ADMIN)->getJson(self::URL . $april)->assertOk()));

        // The super admin sees all the units.
        $this->assertSame(
            [$typeTwo->serial_number, $typeOne->serial_number, $elsewhere->serial_number],
            $this->serials($this->as(self::SUPER_ADMIN)->getJson(self::URL . $april)->assertOk())
        );

        // An admin of one type sees that type only.
        $this->adminOf('1000090', [2]);
        $narrow = $this->as('1000090')->getJson(self::URL . '?per_page=100')->assertOk();
        $this->assertSame([2], array_values(array_unique(array_column($narrow->json('data'), 'booking_type_id'))));
        $this->assertSame(Booking::where('unit_id', 1)->where('booking_type_id', 2)->count(), $narrow->json('meta.total'));
    }

    public function test_a_type_the_admin_only_handles_stays_out_of_their_report(): void
    {
        // Admin of type 2 and vice approver of type 1: their queue holds the type 1 bookings waiting for them, their report does not.
        $this->adminOf('1000092', [2])->givePermissionTo(PermissionEnum::VICE_APPROVER->keyForId(1));
        $handled = $this->bookingAt('2025-05-01 10:00:00', ['booking_type_id' => 1, 'status_id' => 1]);
        $administered = $this->bookingAt('2025-05-02 10:00:00', ['booking_type_id' => 2, 'status_id' => 1]);

        $queue = $this->as('1000092')->getJson('/api/bookings-approver-management/bookings?per_page=100&from=2025-05-01&to=2025-05-02')->assertOk();
        $this->assertContains((int) $handled->serial_number, array_map('intval', array_column($queue->json('data'), 'serial_number')), 'the booking is theirs to handle');

        $report = $this->as('1000092')->getJson(self::URL . '?from_created_at=2025-05-01&to_created_at=2025-05-02')->assertOk();
        $this->assertSame([(int) $administered->serial_number], $this->serials($report));

        $rows = $this->readWorkbook($this->sentBytes($this->as('1000092')->postJson(self::URL . '/export', ['from_created_at' => '2025-05-01', 'to_created_at' => '2025-05-02'])->assertOk()))['rows'];
        $this->assertSame([(int) $administered->serial_number], array_map('intval', array_column(array_slice($rows, 1), 0)));
    }

    public function test_only_the_admin_and_the_super_admin_may_use_the_report(): void
    {
        foreach ([self::APPROVER, self::VICE_APPROVER, self::PLAIN_USER, self::NO_ROLE] as $person) {
            $this->as($person)->getJson(self::URL)->assertForbidden()->assertJsonPath('message', HttpStatusCodeEnum::DENIED_MESSAGE);
            $this->as($person)->postJson(self::URL . '/export')->assertForbidden()->assertJsonPath('message', HttpStatusCodeEnum::DENIED_MESSAGE);
        }

        // The admin role without a booking type to administer gets in at the door and is stopped by the service (and the attempt is audited).
        $nobody = User::factory()->create(['personal_number' => '1000091', 'unit_id' => 1]);
        $nobody->assignRole(RoleEnum::ADMIN->value);
        $this->as('1000091')->getJson(self::URL)->assertForbidden();
        $this->as('1000091')->postJson(self::URL . '/export')->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', 'report.export')->where('result', 'denied')->where('actor_id', '1000091')->count());

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL . '/export')->assertUnauthorized();

        Storage::disk('minio')->assertDirectoryEmpty(self::FOLDER);
    }

    public function test_the_filters_are_checked_before_anything_is_read_or_written(): void
    {
        $super = fn () => $this->as(self::SUPER_ADMIN);

        foreach ([
            'from_created_at=03/11/2026' => 'from_created_at',
            'from_created_at=2026-02-30' => 'from_created_at',
            'to_created_at=yesterday' => 'to_created_at',
            'from_created_at=2026-02-01&to_created_at=2026-01-01' => 'to_created_at',
            'booking_type_id=999' => 'booking_type_id',
            'booking_type_id=abc' => 'booking_type_id',
            'status_id=99' => 'status_id',
            'page=0' => 'page',
            'per_page=0' => 'per_page',
        ] as $query => $field) {
            $super()->getJson(self::URL . '?' . $query)->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $super()->getJson(self::URL . '?from_created_at=03/11/2026')
            ->assertJsonPath('errors.from_created_at.0', 'תאריך ההתחלה חייב להיות בתבנית שנה-חודש-יום, למשל 2026-11-03.');

        // The export checks the same filters, from the body.
        $super()->postJson(self::URL . '/export', ['booking_type_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('booking_type_id');
        $super()->postJson(self::URL . '/export', ['from_created_at' => '2026-02-01', 'to_created_at' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('to_created_at');

        Storage::disk('minio')->assertDirectoryEmpty(self::FOLDER);
        $this->assertSame(0, AuditLog::where('action', 'report.export')->count());
    }

    //------------------------------- The export ------------------------------//

    public function test_the_export_sends_an_excel_file_and_keeps_a_copy_in_the_bucket(): void
    {
        User::where('personal_number', self::PLAIN_USER)->update(['full_name' => 'דנה כהן', 'personal_id' => '012345678']);
        $booking = $this->bookingAt('2025-06-10 09:00:00', ['booking_type_id' => 2, 'status_id' => 3, 'status_changed_at' => '2025-06-11 20:30:00']);

        $this->as(self::SUPER_ADMIN);
        Carbon::setTestNow('2026-11-03 12:35:20');

        $response = $this->postJson(self::URL . '/export', ['from_created_at' => '2025-06-10', 'to_created_at' => '2025-06-10'])->assertOk();

        // A download named bookings_<date>_<time>.xlsx, with the time of the report timezone (two hours ahead of UTC in November).
        $name = 'bookings_2026-11-03_14-35-20.xlsx';
        $this->assertSame(BookingReportService::MIME_TYPE, $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=' . $name, $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));

        $bytes = $this->sentBytes($response);

        // The copy in the bucket folder export-report is the file that was sent.
        Storage::disk('minio')->assertExists(self::FOLDER . '/' . $name);
        $this->assertSame($bytes, Storage::disk('minio')->get(self::FOLDER . '/' . $name));

        // One sheet: the headings, then the booking - the dates in the local time of Israel (UTC+3 in June), the ID number as text.
        $workbook = $this->readWorkbook($bytes);
        $this->assertSame('קריאות', $workbook['name']);
        $this->assertCount(2, $workbook['rows']);
        $this->assertSame(self::HEADINGS, $workbook['rows'][0]);
        $this->assertSame([
            (int) $booking->serial_number,
            BookingType::find(2)->display_name,
            Status::find(3)->display_name,
            'דנה כהן',
            '012345678',
            self::PLAIN_USER,
            Unit::find(1)->displayName(),
            '2025-06-10 12:00',
            '2025-06-11 23:30',
        ], array_map(fn (mixed $cell): mixed => is_numeric($cell) && !is_string($cell) ? (int) $cell : $cell, $workbook['rows'][1]));

        // Right to left, headings frozen, a filter on the headings.
        $xml = $this->sheetXml($bytes);
        $this->assertStringContainsString('rightToLeft="true"', $xml);
        $this->assertStringContainsString('state="frozen"', $xml);
        $this->assertStringContainsString('<autoFilter ref="A1:I2"/>', $xml);
    }

    public function test_the_export_holds_the_same_bookings_as_the_list(): void
    {
        $this->bookingAt('2025-06-10 09:00:00', ['booking_type_id' => 1, 'status_id' => 1]);
        $this->bookingAt('2025-06-15 09:00:00', ['booking_type_id' => 1, 'status_id' => 3]);
        $this->bookingAt('2025-06-20 09:00:00', ['booking_type_id' => 2, 'status_id' => 1]);
        $this->bookingAt('2025-07-05 09:00:00', ['booking_type_id' => 2, 'status_id' => 3]);
        $elsewhere = $this->bookingAt('2025-06-12 09:00:00', ['unit_id' => 2, 'booking_type_id' => 1, 'status_id' => 1]);

        foreach ([self::SUPER_ADMIN, self::ADMIN] as $person) {
            foreach ([
                [],
                ['booking_type_id' => 2],
                ['status_id' => 3],
                ['from_created_at' => '2025-06-01', 'to_created_at' => '2025-06-30'],
                ['booking_type_id' => 2, 'status_id' => 1, 'from_created_at' => '2025-06-01', 'to_created_at' => '2025-06-30'],
                ['booking_type_id' => 1, 'status_id' => 3, 'from_created_at' => '2025-06-01', 'to_created_at' => '2025-06-10'],
            ] as $filters) {
                $listed = $this->as($person)->getJson(self::URL . '?per_page=100&' . http_build_query($filters))->assertOk();

                $rows = $this->readWorkbook($this->sentBytes($this->as($person)->postJson(self::URL . '/export', $filters)->assertOk()))['rows'];

                $this->assertCount(1 + $listed->json('meta.total'), $rows, $person . ' ' . json_encode($filters));

                // Same bookings, same order: the list's page (at most 100) against the export's first rows; the count
                // above covers the rest (with more booking types, the seeded bookings pass 100).
                $page = $this->serials($listed);
                $this->assertSame($page, array_map('intval', array_column(array_slice($rows, 1, count($page)), 0)));
            }
        }

        // The admin's file never holds the other unit; the filters can also come in the query string.
        $adminRows = $this->readWorkbook($this->sentBytes($this->as(self::ADMIN)->postJson(self::URL . '/export?from_created_at=2025-06-12&to_created_at=2025-06-12')->assertOk()))['rows'];
        $this->assertCount(1, $adminRows, 'only the headings: the one booking of that day is in unit 2');
        $this->assertNotContains((int) $elsewhere->serial_number, array_map('intval', array_column($adminRows, 0)));

        $superRows = $this->readWorkbook($this->sentBytes($this->as(self::SUPER_ADMIN)->postJson(self::URL . '/export?from_created_at=2025-06-12&to_created_at=2025-06-12')->assertOk()))['rows'];
        $this->assertSame([(int) $elsewhere->serial_number], array_map('intval', array_column(array_slice($superRows, 1), 0)));
    }

    public function test_a_name_that_looks_like_a_formula_stays_text(): void
    {
        $formula = '=HYPERLINK("http://example.com/x","click")';
        User::where('personal_number', self::PLAIN_USER)->update(['full_name' => $formula]);
        $this->bookingAt('2025-08-01 10:00:00');

        $bytes = $this->sentBytes($this->as(self::SUPER_ADMIN)->postJson(self::URL . '/export', ['from_created_at' => '2025-08-01', 'to_created_at' => '2025-08-01'])->assertOk());

        $xml = $this->sheetXml($bytes);
        $this->assertStringNotContainsString('<f>', $xml, 'no cell may hold a formula');
        $this->assertStringContainsString('t="inlineStr"><is><t>=HYPERLINK(', $xml);
        $this->assertSame($formula, $this->readWorkbook($bytes)['rows'][1][3]);
    }

    public function test_two_exports_in_the_same_second_keep_both_files(): void
    {
        $this->as(self::SUPER_ADMIN);
        Carbon::setTestNow('2026-11-03 12:35:20');

        $expected = ['bookings_2026-11-03_14-35-20.xlsx', 'bookings_2026-11-03_14-35-20_2.xlsx', 'bookings_2026-11-03_14-35-20_3.xlsx'];

        foreach ($expected as $name) {
            $response = $this->postJson(self::URL . '/export', ['to_created_at' => '2001-01-01'])->assertOk();

            // The download carries the name the copy has in the bucket.
            $this->assertStringContainsString('filename=' . $name, $response->headers->get('Content-Disposition'));
            $this->sentBytes($response);
        }

        $this->assertEqualsCanonicalizing($expected, array_map('basename', Storage::disk('minio')->allFiles(self::FOLDER)));
    }

    public function test_the_export_is_audited_and_the_list_is_not(): void
    {
        $this->bookingAt('2025-06-10 09:00:00', ['booking_type_id' => 2]);
        $this->bookingAt('2025-06-11 09:00:00', ['booking_type_id' => 2]);
        $this->bookingAt('2025-06-12 09:00:00', ['booking_type_id' => 1]);

        $this->as(self::ADMIN)->getJson(self::URL)->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'report.export')->count());

        $filters = ['booking_type_id' => 2, 'from_created_at' => '2025-06-01', 'to_created_at' => '2025-06-30'];
        $this->sentBytes($this->as(self::ADMIN)->postJson(self::URL . '/export', $filters + ['page' => 3])->assertOk());

        $audit = AuditLog::where('action', 'report.export')->sole();
        $name = basename(Storage::disk('minio')->allFiles(self::FOLDER)[0]);

        $this->assertSame(self::ADMIN, $audit->actor_id);
        $this->assertSame('success', $audit->result);
        $this->assertSame('report', $audit->entity_type);
        $this->assertSame($name, $audit->entity_id);
        $this->assertSame(2, (int) $audit->booking_type_id);
        $this->assertEquals(['file' => $name, 'key' => self::FOLDER . '/' . $name, 'rows' => 2, 'filters' => $filters], $audit->after);
    }

    public function test_an_export_of_nothing_is_still_a_file_with_the_headings(): void
    {
        $bytes = $this->sentBytes($this->as(self::SUPER_ADMIN)->postJson(self::URL . '/export', ['from_created_at' => '2001-01-01', 'to_created_at' => '2001-01-02'])->assertOk());

        $this->assertSame([self::HEADINGS], $this->readWorkbook($bytes)['rows']);
        $this->assertStringContainsString('<autoFilter ref="A1:I1"/>', $this->sheetXml($bytes));
        $this->assertCount(1, Storage::disk('minio')->allFiles(self::FOLDER));
    }

    public function test_a_long_export_loses_and_repeats_no_booking(): void
    {
        $owner = User::where('personal_number', self::PLAIN_USER)->value('id');
        $rows = [];

        // More than two reads of 500 bookings; each one minute after the one before.
        foreach (range(1, 1100) as $i) {
            $at = Carbon::parse('2024-01-01 00:00:00', 'UTC')->addMinutes($i)->format('Y-m-d H:i:s');
            $rows[] = [
                'uuid' => (string) Str::uuid(), 'serial_number' => 820000000 + $i, 'unit_id' => 1, 'booking_type_id' => 3, 'user_id' => $owner,
                'status_id' => 1, 'version' => 1, 'ext_status_id' => 1, 'status_source' => 'external',
                'status_changed_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('bookings')->insert($chunk);
        }

        $bytes = $this->sentBytes($this->as(self::SUPER_ADMIN)->postJson(self::URL . '/export', ['from_created_at' => '2024-01-01', 'to_created_at' => '2024-12-31'])->assertOk());
        $sheet = $this->readWorkbook($bytes)['rows'];

        $this->assertCount(1101, $sheet);
        $this->assertSame(range(820001100, 820000001), array_map('intval', array_column(array_slice($sheet, 1), 0)));
        $this->assertStringContainsString('<autoFilter ref="A1:I1101"/>', $this->sheetXml($bytes));
    }

    public function test_a_bucket_that_cannot_be_written_stops_the_export(): void
    {
        // A root below a plain file cannot be created.
        $blocker = tempnam(sys_get_temp_dir(), 'blocker_');
        config([
            'filesystems.disks.broken' => ['driver' => 'local', 'root' => $blocker . DIRECTORY_SEPARATOR . 'bucket', 'throw' => true],
            'filesystems.storage_service' => 'broken',
        ]);
        $leftovers = fn (): int => count(glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookings_*') ?: []);
        $before = $leftovers();

        $this->as(self::SUPER_ADMIN)->postJson(self::URL . '/export')
            ->assertStatus(503)
            ->assertJsonPath('message', 'שמירת הדוח בגיבוי באחסון הקבצים נכשלה, ולכן הקובץ לא נשלח. נסו שוב בעוד כמה דקות.');

        $this->assertSame($before, $leftovers(), 'the file that was built is not left behind');
        $this->assertSame(1, AuditLog::where('action', 'report.export')->where('result', 'failed')->count());
        $this->assertSame(0, AuditLog::where('action', 'report.export')->where('result', 'success')->count());

        @unlink($blocker);
    }
}

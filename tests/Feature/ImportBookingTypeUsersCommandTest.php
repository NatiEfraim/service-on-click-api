<?php

namespace Tests\Feature;

use App\Enums\BookingTypeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\User;
use App\Services\ImportUsersXlsxService;
use Database\Seeders\ProdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The four users:import-* commands of IMPORT_BOOKING_TYPE_USERS.md, each reading its own file from the bucket's
 * import-xlsx folder: everyone gets the file's user_{id} permissions plus the common infosec + ICT ones (user_14 is
 * skipped while the clearance grant has no USER role). Each file's real shape is reproduced: headers with extra
 * columns, the headerless maintenance file, 8-digit IDs whose leading zero Excel dropped, phones without their
 * leading zero, and names with RTL-flipped brackets. Existing people only have empty fields filled, never overwritten.
 */
class ImportBookingTypeUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = 'import-xlsx';
    private const LOADED = 'loaded-xlsx';

    /** The real header row of users_car_booking.xlsx (the unit column is ignored). */
    private const CAR_HEADERS = ['מספר אישי', 'ת.ז', 'תיאור יחידה', 'שם פרטי', 'שם משפחה', 'מספר נייד'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProdSeeder::class);

        Storage::fake('minio');
        config([
            'filesystems.storage_service' => 'minio',
            // The skipped-row warnings are expected here; they do not belong in the developer's log file.
            'logging.default' => 'null',
        ]);
    }

    /**
     * Writes an xlsx into the inbox the way Excel exported the real files: numbers as number cells.
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, string>|null $headers null writes a file without a header row (the maintenance file)
     */
    private function putXlsx(string $name, array $rows, ?array $headers): void
    {
        $path = tempnam(sys_get_temp_dir(), 'users-xlsx-test-');

        $writer = new Writer();
        $writer->openToFile($path);

        if ($headers !== null) {
            $writer->addRow(Row::fromValues($headers));
        }

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        Storage::disk('minio')->put(self::INBOX . '/' . $name, (string) file_get_contents($path));
        @unlink($path);
    }

    /** @return array<int, string> the common user_{id} permission names that exist (today: 15, 16, 20-25 - never user_14) */
    private function commonPermissionNames(): array
    {
        $names = array_filter(array_map(
            fn (BookingTypeEnum $bookingType): ?string => PermissionEnum::USER->keyForBooking($bookingType),
            ImportUsersXlsxService::COMMON_BOOKING_TYPES
        ));

        return array_values(array_filter(
            $names,
            fn (string $name): bool => Permission::query()->where('name', $name)->where('guard_name', 'passport')->exists()
        ));
    }

    /** @return array<int, string> sorted: the file's own user_{id} names plus the common ones */
    private function expectedNames(BookingTypeEnum ...$bookingTypes): array
    {
        $names = array_map(
            fn (BookingTypeEnum $bookingType): string => (string) PermissionEnum::USER->keyForBooking($bookingType),
            $bookingTypes
        );

        $names = array_values(array_unique([...$names, ...$this->commonPermissionNames()]));
        sort($names);

        return $names;
    }

    private function carPermission(): string
    {
        return (string) PermissionEnum::USER->keyForBooking(BookingTypeEnum::CAR_BOOKING);
    }

    public function test_each_command_grants_its_file_types_plus_the_common_types(): void
    {
        // Each file in its real shape: car with headers, maintenance without a header row, the food/transportation
        // file with its extra (and mislabeled) columns, and the equipment file with its 5-column layout.
        $this->putXlsx('users_car_booking.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
        ], self::CAR_HEADERS);

        $this->putXlsx('users_car_maintanence_booking.xlsx', [
            [7492966, 300000221, 'חט"ל 99', 'תם', 'ברנד', 547477456],
        ], null);

        $this->putXlsx('users_out_unit_food_booking_transportion_booking.xlsx', [
            [99808781, 300000338, 'חט"ל 99', 'לירז', 'עזרא', 'משקים ומטה', 'ע"ץ', 528707372, 'עובד צה"ל'],
        ], ['מספר אישי', 'ת.ז', 'תיאור יחידה', 'שם פרטי', 'שם משפחה', 'דרגה', 'תיאור דרגה', 'מספר נייד', 'תיאור סוג שירות']);

        $this->putXlsx('users_equipment_barber_in_unit_food_construction_booking.xlsx', [
            // Also in the car file: the grants of both commands add up on one user.
            [7716506, 123456782, 'ליאה', 'טל-אור', 528434874],
            // An 8-digit ID (Excel dropped the leading zero) and a name with RTL-flipped brackets.
            [7000111, 12345674, 'יועז )אליעזר(', 'כהן', 501234567],
        ], ['מספר אישי', 'ת.ז', 'שם פרטי', 'שם משפחה', 'מספר נייד']);

        $this->artisan('users:import-car-booking')->assertSuccessful();
        $this->artisan('users:import-car-maintenance-booking')->assertSuccessful();
        $this->artisan('users:import-out-unit-food-transportation')->assertSuccessful();
        $this->artisan('users:import-equipment-barber-in-unit-food-construction')->assertSuccessful();

        // The common list today: infosec 15-16 and the 6 ICT types. user_14 has no USER role, so it must not exist
        // and the commands must have succeeded without it.
        $common = $this->commonPermissionNames();
        $this->assertCount(8, $common);
        $this->assertNotContains(PermissionEnum::USER->keyForBooking(BookingTypeEnum::CLEARANCE_GRANT_BOOKING), $common);

        // The car person: created with the unit, a 10-digit phone, the plain user role, and the grants of both files.
        $car = User::where('personal_number', '7716506')->firstOrFail();
        $this->assertSame('ליאה טל-אור', $car->full_name);
        $this->assertSame('123456782', $car->personal_id);
        $this->assertSame('0528434874', $car->phone);
        $this->assertSame(UnitEnum::H->value, (int) $car->unit_id);
        $this->assertTrue($car->hasRole(RoleEnum::USER->toSpatieRoleName()));
        $this->assertSame(
            $this->expectedNames(
                BookingTypeEnum::CAR_BOOKING,
                BookingTypeEnum::EQUIPMENT_BOOKING_MALE,
                BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE,
                BookingTypeEnum::BARBER_BOOKING,
                BookingTypeEnum::IN_UNIT_FOOD_BOOKING,
                BookingTypeEnum::CONSTRUCTION_BOOKING,
            ),
            $car->getDirectPermissions()->pluck('name')->sort()->values()->all()
        );

        // The headerless maintenance file was read by column position.
        $maintenance = User::where('personal_number', '7492966')->firstOrFail();
        $this->assertSame('תם ברנד', $maintenance->full_name);
        $this->assertSame(
            $this->expectedNames(BookingTypeEnum::CAR_MAINTENANCE_BOOKING),
            $maintenance->getDirectPermissions()->pluck('name')->sort()->values()->all()
        );

        // The food/transportation file was read by header names, its extra columns ignored; 8-digit personal numbers pass.
        $food = User::where('personal_number', '99808781')->firstOrFail();
        $this->assertSame('לירז עזרא', $food->full_name);
        $this->assertSame('0528707372', $food->phone);
        $this->assertSame(
            $this->expectedNames(BookingTypeEnum::OUT_UNIT_FOOD_BOOKING, BookingTypeEnum::TRANSPORTATION_BOOKING),
            $food->getDirectPermissions()->pluck('name')->sort()->values()->all()
        );

        // The equipment person: the ID zero-padded back to 9 digits and the brackets unflipped.
        $equipment = User::where('personal_number', '7000111')->firstOrFail();
        $this->assertSame('012345674', $equipment->personal_id);
        $this->assertSame('יועז (אליעזר) כהן', $equipment->full_name);

        // All four files moved on.
        foreach ([
            'users_car_booking.xlsx',
            'users_car_maintanence_booking.xlsx',
            'users_out_unit_food_booking_transportion_booking.xlsx',
            'users_equipment_barber_in_unit_food_construction_booking.xlsx',
        ] as $name) {
            Storage::disk('minio')->assertMissing(self::INBOX . '/' . $name);
            Storage::disk('minio')->assertExists(self::LOADED . '/' . $name);
        }
    }

    public function test_existing_people_only_have_empty_fields_filled_and_a_second_run_changes_nothing(): void
    {
        $admin = User::factory()->create([
            'personal_number' => '7000222', 'personal_id' => '300000445', 'full_name' => 'שם קיים',
            'phone' => '0500000001', 'unit_id' => UnitEnum::H->value,
        ]);
        $admin->assignRole(RoleEnum::ADMIN->value);
        $admin->forceFill(['is_exported_role' => true])->save();

        // Known from the mobile side by the ID number alone: gets the personal number instead of a duplicate row.
        $byIdOnly = User::factory()->create(['personal_number' => null, 'personal_id' => '300000551', 'unit_id' => UnitEnum::H->value]);

        // The equipment file's 24 pairs: the database already holds another personal number for this ID.
        $twoNumbers = User::factory()->create(['personal_number' => '6895992', 'personal_id' => '300000668', 'unit_id' => UnitEnum::H->value]);

        // A row whose personal number and ID belong to two different people must not merge them.
        $conflictA = User::factory()->create(['personal_number' => '7000555', 'personal_id' => '300000775', 'unit_id' => UnitEnum::H->value]);
        $conflictB = User::factory()->create(['personal_number' => '7000666', 'personal_id' => '300000882', 'unit_id' => UnitEnum::H->value]);

        $rows = [
            [7000222, 300000445, 'חט"ל 99', 'דנה', 'לוי', 521111111],
            [7000333, 300000551, 'חט"ל 99', 'יוסי', 'כהן', 522222222],
            [99809829, 300000668, 'חט"ל 99', 'אבי', 'לוי', 523333333],
            [7000555, 300000882, 'חט"ל 99', 'סתירה', 'כפולה', 524444444],
            [7000777, 123456789, 'חט"ל 99', 'ספרת', 'ביקורת', 525555555], // the check digit fails
        ];

        $this->putXlsx('users_car_booking.xlsx', $rows, self::CAR_HEADERS);
        $this->artisan('users:import-car-booking')->assertSuccessful();

        // The admin kept their one role and every existing detail; only the permissions and the export flag changed.
        $admin->refresh();
        $this->assertSame('שם קיים', $admin->full_name);
        $this->assertSame('0500000001', $admin->phone);
        $this->assertSame([RoleEnum::ADMIN->toSpatieRoleName()], $admin->getRoleNames()->all());
        $this->assertSame(
            $this->expectedNames(BookingTypeEnum::CAR_BOOKING),
            $admin->getDirectPermissions()->pluck('name')->sort()->values()->all()
        );
        $this->assertFalse((bool) $admin->is_exported_role);

        $byIdOnly->refresh();
        $this->assertSame('7000333', $byIdOnly->personal_number);
        $this->assertTrue($byIdOnly->hasRole(RoleEnum::USER->toSpatieRoleName()));

        // Same ID with a second personal number: one user, and the database's number wins.
        $twoNumbers->refresh();
        $this->assertSame('6895992', $twoNumbers->personal_number);
        $this->assertTrue($twoNumbers->hasDirectPermission($this->carPermission()));
        $this->assertSame(0, User::where('personal_number', '99809829')->count());

        // The conflicting row and the invalid-ID row were skipped whole: no grants, no new user.
        $this->assertCount(0, $conflictA->fresh()->getDirectPermissions());
        $this->assertCount(0, $conflictB->fresh()->getDirectPermissions());
        $this->assertSame(0, User::where('personal_number', '7000777')->count());

        // The same list again (--force, so the identical-file guard does not skip it): nothing doubles,
        // and nobody is marked for the users export file again.
        $admin->forceFill(['is_exported_role' => true])->save();
        $usersBefore = User::count();
        $permissionsBefore = $admin->fresh()->getDirectPermissions()->count();

        $this->putXlsx('users_car_booking.xlsx', $rows, self::CAR_HEADERS);
        $this->artisan('users:import-car-booking --force')->assertSuccessful();

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($permissionsBefore, $admin->fresh()->getDirectPermissions()->count());
        $this->assertTrue((bool) $admin->fresh()->is_exported_role);
    }

    public function test_revoke_missing_removes_only_the_file_permissions_of_absent_users(): void
    {
        $removableMedia = (string) PermissionEnum::USER->keyForBooking(BookingTypeEnum::REMOVABLE_MEDIA_BOOKING);

        // Someone who holds the car permission and a common one but is not in the file.
        $absent = User::factory()->create(['personal_number' => '7000999', 'personal_id' => '300000999', 'unit_id' => UnitEnum::H->value]);
        $absent->assignRole(RoleEnum::USER->value);
        $absent->givePermissionTo([$this->carPermission(), $removableMedia]);
        $absent->forceFill(['is_exported_role' => true])->save();

        $this->putXlsx('users_car_booking.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
        ], self::CAR_HEADERS);

        $this->artisan('users:import-car-booking', ['--revoke-missing' => true, '--force' => true])->assertSuccessful();

        // The file is now the full list of its own type - but the common permission stays.
        $absent->refresh();
        $this->assertFalse($absent->hasDirectPermission($this->carPermission()));
        $this->assertTrue($absent->hasDirectPermission($removableMedia));
        $this->assertFalse((bool) $absent->is_exported_role);

        $this->assertTrue(User::where('personal_number', '7716506')->firstOrFail()->hasDirectPermission($this->carPermission()));
    }

    public function test_a_file_already_sitting_in_loaded_xlsx_is_reported_not_reimported(): void
    {
        $this->putXlsx('users_car_booking.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
        ], self::CAR_HEADERS);
        $this->artisan('users:import-car-booking')->assertSuccessful();

        // The exact same bytes come back to the inbox: the run says the file is already loaded and touches nothing.
        $loadedBytes = (string) Storage::disk('minio')->get(self::LOADED . '/users_car_booking.xlsx');
        Storage::disk('minio')->put(self::INBOX . '/users_car_booking.xlsx', $loadedBytes);

        $granted = User::where('personal_number', '7716506')->firstOrFail()->getDirectPermissions()->count();

        $this->artisan('users:import-car-booking')
            ->expectsOutputToContain('already in ' . self::LOADED)
            ->assertSuccessful();

        Storage::disk('minio')->assertExists(self::INBOX . '/users_car_booking.xlsx');
        $this->assertSame($granted, User::where('personal_number', '7716506')->firstOrFail()->getDirectPermissions()->count());

        // --force imports it anyway (idempotent), and the file moves on under a dated name.
        $this->artisan('users:import-car-booking --force')->assertSuccessful();
        Storage::disk('minio')->assertMissing(self::INBOX . '/users_car_booking.xlsx');

        // A renewed list under the same name has different bytes: it imports normally, no --force needed.
        $this->putXlsx('users_car_booking.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
            [7000111, 12345674, 'חט"ל 99', 'יועז', 'כהן', 501234567],
        ], self::CAR_HEADERS);

        $this->artisan('users:import-car-booking')->assertSuccessful();
        $this->assertSame(1, User::where('personal_number', '7000111')->count());
        Storage::disk('minio')->assertMissing(self::INBOX . '/users_car_booking.xlsx');
    }

    public function test_missing_broken_or_unseeded_files_fail_and_dry_run_saves_nothing(): void
    {
        $row = [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874];

        // Nothing waiting under this command's name.
        $this->artisan('users:import-car-booking')->assertFailed();

        // A file whose bytes are not an xlsx: nothing written, the file stays for a fixed retry.
        Storage::disk('minio')->put(self::INBOX . '/users_car_booking.xlsx', 'not an xlsx at all');
        $this->artisan('users:import-car-booking')->assertFailed();
        Storage::disk('minio')->assertExists(self::INBOX . '/users_car_booking.xlsx');
        Storage::disk('minio')->delete(self::INBOX . '/users_car_booking.xlsx');

        // --dry-run reads everything and keeps nothing: no user, the file still waiting.
        $this->putXlsx('users_car_booking.xlsx', [$row], self::CAR_HEADERS);
        $this->artisan('users:import-car-booking --dry-run')->assertSuccessful();
        $this->assertSame(0, User::where('personal_number', '7716506')->count());
        Storage::disk('minio')->assertExists(self::INBOX . '/users_car_booking.xlsx');

        // The real run after the rehearsal.
        $this->artisan('users:import-car-booking')->assertSuccessful();
        $this->assertSame(1, User::where('personal_number', '7716506')->count());
        Storage::disk('minio')->assertMissing(self::INBOX . '/users_car_booking.xlsx');
        Storage::disk('minio')->assertExists(self::LOADED . '/users_car_booking.xlsx');

        // A FILE permission that is missing (unlike a common one) is a hard failure: nothing saved, the file stays.
        Permission::query()->where('name', $this->carPermission())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->putXlsx('users_car_booking.xlsx', [
            [7000111, 12345674, 'חט"ל 99', 'יועז', 'כהן', 501234567],
        ], self::CAR_HEADERS);
        $this->artisan('users:import-car-booking')->assertFailed();
        $this->assertSame(0, User::where('personal_number', '7000111')->count());
        Storage::disk('minio')->assertExists(self::INBOX . '/users_car_booking.xlsx');
    }
}

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
 * The four *:xlsx user imports of IMPORT_BOOKING_TYPE_USERS.md, each reading its own file from the bucket's import-xlsx
 * folder: everyone in the file gets its user_{id} permissions plus the common infosec + ICT ones (user_14 is skipped
 * while the clearance grant has no USER role), and everyone else loses the file's own permissions - never the common
 * ones. Each file's real shape is reproduced: headers with extra columns, the headerless maintenance file, 8-digit IDs
 * whose leading zero Excel dropped, phones without their leading zero, and names with RTL-flipped brackets. Existing
 * people only have empty fields filled, never overwritten.
 */
class ImportBookingTypeUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = 'import-xlsx';
    private const LOADED = 'loaded-xlsx';

    /** The real header row of car_booking_users.xlsx (the unit column is ignored). */
    private const CAR_HEADERS = ['מספר אישי', 'ת.ז', 'תיאור יחידה', 'שם פרטי', 'שם משפחה', 'מספר נייד'];

    /** The real header row of out_unit_food_booking.xlsx (unit, rank and service type are ignored). */
    private const OUT_UNIT_FOOD_HEADERS = ['מספר אישי', 'ת.ז', 'תיאור יחידה', 'שם פרטי', 'שם משפחה', 'דרגה', 'תיאור דרגה', 'מספר נייד', 'תיאור סוג שירות'];

    /** The real header row of users.xlsx. */
    private const USERS_HEADERS = ['מספר אישי', 'ת.ז', 'שם פרטי', 'שם משפחה', 'מספר נייד'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProdSeeder::class);

        Storage::fake('minio');
        config([
            'filesystems.storage_service' => 'minio',
            // The skipped-row and revoke warnings are expected here; they do not belong in the developer's log file.
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
            fn (BookingTypeEnum $bookingType): string => $this->permission($bookingType),
            $bookingTypes
        );

        $names = array_values(array_unique([...$names, ...$this->commonPermissionNames()]));
        sort($names);

        return $names;
    }

    private function permission(BookingTypeEnum $bookingType): string
    {
        return (string) PermissionEnum::USER->keyForBooking($bookingType);
    }

    /** @return array<int, string> sorted direct permission names of the user */
    private function namesOf(User $user): array
    {
        return $user->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all();
    }

    public function test_each_command_grants_its_file_types_plus_the_common_types(): void
    {
        // Each file in its real shape: car with headers, maintenance without a header row, the out-unit food file with
        // its extra (and mislabeled) columns, and users.xlsx with its 5-column layout.
        $this->putXlsx('car_booking_users.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
        ], self::CAR_HEADERS);

        $this->putXlsx('car_maintenance_booking.xlsx', [
            [7492966, 300000221, 'חט"ל 99', 'תם', 'ברנד', 547477456],
        ], null);

        $this->putXlsx('out_unit_food_booking.xlsx', [
            [99808781, 300000338, 'חט"ל 99', 'לירז', 'עזרא', 'משקים ומטה', 'ע"ץ', 528707372, 'עובד צה"ל'],
        ], self::OUT_UNIT_FOOD_HEADERS);

        $this->putXlsx('users.xlsx', [
            // Everyone from the other three files is in users.xlsx too: the grants of both commands add up on one user.
            [7716506, 123456782, 'ליאה', 'טל-אור', 528434874],
            [7492966, 300000221, 'תם', 'ברנד', 547477456],
            [99808781, 300000338, 'לירז', 'עזרא', 528707372],
            // An 8-digit ID (Excel dropped the leading zero) and a name with RTL-flipped brackets.
            [7000111, 12345674, 'יועז )אליעזר(', 'כהן', 501234567],
        ], self::USERS_HEADERS);

        $this->artisan('importUser:xlsx')->assertSuccessful();
        $this->artisan('importCarBooking:xlsx')->assertSuccessful();
        $this->artisan('importCarMaintenanceBooking:xlsx')->assertSuccessful();
        $this->artisan('importOutUnitFoodBooking:xlsx')->assertSuccessful();

        // The common list today: infosec 15-16 and the 6 ICT types. user_14 has no USER role, so it must not exist
        // and the commands must have succeeded without it.
        $common = $this->commonPermissionNames();
        $this->assertCount(8, $common);
        $this->assertNotContains(PermissionEnum::USER->keyForBooking(BookingTypeEnum::CLEARANCE_GRANT_BOOKING), $common);

        $usersTypes = [
            BookingTypeEnum::EQUIPMENT_BOOKING_MALE,
            BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE,
            BookingTypeEnum::BARBER_BOOKING,
            BookingTypeEnum::TRANSPORTATION_BOOKING,
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING,
            BookingTypeEnum::CONSTRUCTION_BOOKING,
        ];

        // The car person: created with the unit, a 10-digit phone, the plain user role, and the grants of both files.
        $car = User::where('personal_number', '7716506')->firstOrFail();
        $this->assertSame('ליאה טל-אור', $car->full_name);
        $this->assertSame('123456782', $car->personal_id);
        $this->assertSame('0528434874', $car->phone);
        $this->assertSame(UnitEnum::H->value, (int) $car->unit_id);
        $this->assertTrue($car->hasRole(RoleEnum::USER->toSpatieRoleName()));
        $this->assertSame($this->expectedNames(BookingTypeEnum::CAR_BOOKING, ...$usersTypes), $this->namesOf($car));

        // The headerless maintenance file was read by column position.
        $maintenance = User::where('personal_number', '7492966')->firstOrFail();
        $this->assertSame('תם ברנד', $maintenance->full_name);
        $this->assertSame($this->expectedNames(BookingTypeEnum::CAR_MAINTENANCE_BOOKING, ...$usersTypes), $this->namesOf($maintenance));

        // The out-unit food file was read by header names, its extra columns ignored; 8-digit personal numbers pass.
        // Transportation now comes from users.xlsx, not from this file.
        $food = User::where('personal_number', '99808781')->firstOrFail();
        $this->assertSame('לירז עזרא', $food->full_name);
        $this->assertSame('0528707372', $food->phone);
        $this->assertSame($this->expectedNames(BookingTypeEnum::OUT_UNIT_FOOD_BOOKING, ...$usersTypes), $this->namesOf($food));

        // Someone only in users.xlsx: the ID zero-padded back to 9 digits, the brackets unflipped, and only that file's grants.
        $onlyUsers = User::where('personal_number', '7000111')->firstOrFail();
        $this->assertSame('012345674', $onlyUsers->personal_id);
        $this->assertSame('יועז (אליעזר) כהן', $onlyUsers->full_name);
        $this->assertSame($this->expectedNames(...$usersTypes), $this->namesOf($onlyUsers));

        // All four files moved on.
        foreach (['car_booking_users.xlsx', 'car_maintenance_booking.xlsx', 'out_unit_food_booking.xlsx', 'users.xlsx'] as $name) {
            Storage::disk('minio')->assertMissing(self::INBOX . '/' . $name);
            Storage::disk('minio')->assertExists(self::LOADED . '/' . $name);
        }
    }

    public function test_only_the_people_in_the_file_keep_its_own_permissions(): void
    {
        $car = $this->permission(BookingTypeEnum::CAR_BOOKING);
        $outUnitFood = $this->permission(BookingTypeEnum::OUT_UNIT_FOOD_BOOKING);
        $transportation = $this->permission(BookingTypeEnum::TRANSPORTATION_BOOKING);
        $removableMedia = $this->permission(BookingTypeEnum::REMOVABLE_MEDIA_BOOKING);

        // Someone who holds car, out-unit food, transportation and a common permission, but is in no file.
        $absent = User::factory()->create(['personal_number' => '7000999', 'personal_id' => '300000999', 'unit_id' => UnitEnum::H->value]);
        $absent->assignRole(RoleEnum::USER->value);
        $absent->givePermissionTo([$car, $outUnitFood, $transportation, $removableMedia]);
        $absent->forceFill(['is_exported_role' => true])->save();

        $this->putXlsx('car_booking_users.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
        ], self::CAR_HEADERS);
        $this->artisan('importCarBooking:xlsx')->assertSuccessful();

        // The car file is now the full list of CAR_BOOKING - nothing else was touched.
        $absent->refresh();
        $this->assertFalse($absent->hasDirectPermission($car));
        $this->assertTrue($absent->hasDirectPermission($outUnitFood));
        $this->assertTrue($absent->hasDirectPermission($transportation));
        $this->assertTrue($absent->hasDirectPermission($removableMedia));
        $this->assertFalse((bool) $absent->is_exported_role);
        $this->assertTrue(User::where('personal_number', '7716506')->firstOrFail()->hasDirectPermission($car));

        // The out-unit food file takes only out-unit food: transportation now belongs to users.xlsx.
        $this->putXlsx('out_unit_food_booking.xlsx', [
            [99808781, 300000338, 'חט"ל 99', 'לירז', 'עזרא', 'משקים ומטה', 'ע"ץ', 528707372, 'עובד צה"ל'],
        ], self::OUT_UNIT_FOOD_HEADERS);
        $this->artisan('importOutUnitFoodBooking:xlsx')->assertSuccessful();

        $absent->refresh();
        $this->assertFalse($absent->hasDirectPermission($outUnitFood));
        $this->assertTrue($absent->hasDirectPermission($transportation));
        $this->assertFalse(User::where('personal_number', '99808781')->firstOrFail()->hasDirectPermission($transportation));

        // users.xlsx takes transportation from whoever is not in it - and still never a common permission.
        $this->putXlsx('users.xlsx', [
            [99808781, 300000338, 'לירז', 'עזרא', 528707372],
        ], self::USERS_HEADERS);
        $this->artisan('importUser:xlsx')->assertSuccessful();

        $absent->refresh();
        $this->assertFalse($absent->hasDirectPermission($transportation));
        $this->assertTrue($absent->hasDirectPermission($removableMedia));
        $this->assertTrue(User::where('personal_number', '99808781')->firstOrFail()->hasDirectPermission($transportation));
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

        // The users.xlsx 24 pairs: the database already holds another personal number for this ID.
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

        $this->putXlsx('car_booking_users.xlsx', $rows, self::CAR_HEADERS);
        $this->artisan('importCarBooking:xlsx')->assertSuccessful();

        // The admin kept their one role and every existing detail; only the permissions and the export flag changed.
        $admin->refresh();
        $this->assertSame('שם קיים', $admin->full_name);
        $this->assertSame('0500000001', $admin->phone);
        $this->assertSame([RoleEnum::ADMIN->toSpatieRoleName()], $admin->getRoleNames()->all());
        $this->assertSame($this->expectedNames(BookingTypeEnum::CAR_BOOKING), $this->namesOf($admin));
        $this->assertFalse((bool) $admin->is_exported_role);

        $byIdOnly->refresh();
        $this->assertSame('7000333', $byIdOnly->personal_number);
        $this->assertTrue($byIdOnly->hasRole(RoleEnum::USER->toSpatieRoleName()));

        // Same ID with a second personal number: one user, and the database's number wins.
        $twoNumbers->refresh();
        $this->assertSame('6895992', $twoNumbers->personal_number);
        $this->assertTrue($twoNumbers->hasDirectPermission($this->permission(BookingTypeEnum::CAR_BOOKING)));
        $this->assertSame(0, User::where('personal_number', '99809829')->count());

        // The conflicting row and the invalid-ID row were skipped whole: no grants, no new user.
        $this->assertCount(0, $conflictA->fresh()->getDirectPermissions());
        $this->assertCount(0, $conflictB->fresh()->getDirectPermissions());
        $this->assertSame(0, User::where('personal_number', '7000777')->count());

        // The same list again (--force, so the identical-file guard does not skip it): nothing doubles, nothing is
        // revoked, and nobody is marked for the users export file again.
        $admin->forceFill(['is_exported_role' => true])->save();
        $usersBefore = User::count();
        $permissionsBefore = $admin->fresh()->getDirectPermissions()->count();

        $this->putXlsx('car_booking_users.xlsx', $rows, self::CAR_HEADERS);
        $this->artisan('importCarBooking:xlsx --force')->assertSuccessful();

        $this->assertSame($usersBefore, User::count());
        $this->assertSame($permissionsBefore, $admin->fresh()->getDirectPermissions()->count());
        $this->assertTrue((bool) $admin->fresh()->is_exported_role);
    }

    public function test_a_file_already_sitting_in_loaded_xlsx_is_reported_not_reimported(): void
    {
        $this->putXlsx('car_booking_users.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
        ], self::CAR_HEADERS);
        $this->artisan('importCarBooking:xlsx')->assertSuccessful();

        // The exact same bytes come back to the inbox: the run says the file is already loaded and touches nothing.
        $loadedBytes = (string) Storage::disk('minio')->get(self::LOADED . '/car_booking_users.xlsx');
        Storage::disk('minio')->put(self::INBOX . '/car_booking_users.xlsx', $loadedBytes);

        $granted = User::where('personal_number', '7716506')->firstOrFail()->getDirectPermissions()->count();

        $this->artisan('importCarBooking:xlsx')
            ->expectsOutputToContain('already in ' . self::LOADED)
            ->assertSuccessful();

        Storage::disk('minio')->assertExists(self::INBOX . '/car_booking_users.xlsx');
        $this->assertSame($granted, User::where('personal_number', '7716506')->firstOrFail()->getDirectPermissions()->count());

        // --force imports it anyway (idempotent), and the file moves on under a dated name.
        $this->artisan('importCarBooking:xlsx --force')->assertSuccessful();
        Storage::disk('minio')->assertMissing(self::INBOX . '/car_booking_users.xlsx');

        // Only the dated copy is left in loaded-xlsx: it is recognized as the same file too.
        Storage::disk('minio')->delete(self::LOADED . '/car_booking_users.xlsx');
        Storage::disk('minio')->put(self::INBOX . '/car_booking_users.xlsx', $loadedBytes);
        $this->artisan('importCarBooking:xlsx')
            ->expectsOutputToContain('already in ' . self::LOADED)
            ->assertSuccessful();
        Storage::disk('minio')->delete(self::INBOX . '/car_booking_users.xlsx');

        // A renewed list under the same name has different bytes: it imports normally, no --force needed.
        $this->putXlsx('car_booking_users.xlsx', [
            [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874],
            [7000111, 12345674, 'חט"ל 99', 'יועז', 'כהן', 501234567],
        ], self::CAR_HEADERS);

        $this->artisan('importCarBooking:xlsx')->assertSuccessful();
        $this->assertSame(1, User::where('personal_number', '7000111')->count());
        Storage::disk('minio')->assertMissing(self::INBOX . '/car_booking_users.xlsx');
    }

    public function test_an_old_file_with_a_longer_name_in_loaded_xlsx_does_not_block_users_xlsx(): void
    {
        $this->putXlsx('users.xlsx', [
            [7716506, 123456782, 'ליאה', 'טל-אור', 528434874],
        ], self::USERS_HEADERS);

        // The same list was loaded before under its old name: it starts with "users_" but is not a copy of users.xlsx.
        $bytes = (string) Storage::disk('minio')->get(self::INBOX . '/users.xlsx');
        Storage::disk('minio')->put(self::LOADED . '/users_equipment_barber_in_unit_food_construction_booking.xlsx', $bytes);

        $this->artisan('importUser:xlsx')->assertSuccessful();

        $this->assertTrue(
            User::where('personal_number', '7716506')->firstOrFail()
                ->hasDirectPermission($this->permission(BookingTypeEnum::TRANSPORTATION_BOOKING))
        );
        Storage::disk('minio')->assertMissing(self::INBOX . '/users.xlsx');
        Storage::disk('minio')->assertExists(self::LOADED . '/users.xlsx');
    }

    public function test_missing_broken_empty_or_unseeded_files_fail_and_dry_run_saves_nothing(): void
    {
        $row = [7716506, 123456782, 'חט"ל 99', 'ליאה', 'טל-אור', 528434874];

        // Nothing waiting under this command's name.
        $this->artisan('importCarBooking:xlsx')->assertFailed();

        // A file whose bytes are not an xlsx: nothing written, the file stays for a fixed retry.
        Storage::disk('minio')->put(self::INBOX . '/car_booking_users.xlsx', 'not an xlsx at all');
        $this->artisan('importCarBooking:xlsx')->assertFailed();
        Storage::disk('minio')->assertExists(self::INBOX . '/car_booking_users.xlsx');
        Storage::disk('minio')->delete(self::INBOX . '/car_booking_users.xlsx');

        // A file with no valid person must not strip the permission from everyone: it fails and nothing changes.
        $holder = User::factory()->create(['personal_number' => '7000888', 'personal_id' => '300000886', 'unit_id' => UnitEnum::H->value]);
        $holder->givePermissionTo($this->permission(BookingTypeEnum::CAR_BOOKING));

        $this->putXlsx('car_booking_users.xlsx', [], self::CAR_HEADERS);
        $this->artisan('importCarBooking:xlsx')->assertFailed();
        $this->assertTrue($holder->fresh()->hasDirectPermission($this->permission(BookingTypeEnum::CAR_BOOKING)));
        Storage::disk('minio')->assertExists(self::INBOX . '/car_booking_users.xlsx');
        Storage::disk('minio')->delete(self::INBOX . '/car_booking_users.xlsx');

        // --dry-run reads everything and keeps nothing: no user, the file still waiting.
        $this->putXlsx('car_booking_users.xlsx', [$row], self::CAR_HEADERS);
        $this->artisan('importCarBooking:xlsx --dry-run')->assertSuccessful();
        $this->assertSame(0, User::where('personal_number', '7716506')->count());
        Storage::disk('minio')->assertExists(self::INBOX . '/car_booking_users.xlsx');

        // The real run after the rehearsal.
        $this->artisan('importCarBooking:xlsx')->assertSuccessful();
        $this->assertSame(1, User::where('personal_number', '7716506')->count());
        Storage::disk('minio')->assertMissing(self::INBOX . '/car_booking_users.xlsx');
        Storage::disk('minio')->assertExists(self::LOADED . '/car_booking_users.xlsx');

        // A FILE permission that is missing (unlike a common one) is a hard failure: nothing saved, the file stays.
        Permission::query()->where('name', $this->permission(BookingTypeEnum::CAR_BOOKING))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->putXlsx('car_booking_users.xlsx', [
            [7000111, 12345674, 'חט"ל 99', 'יועז', 'כהן', 501234567],
        ], self::CAR_HEADERS);
        $this->artisan('importCarBooking:xlsx')->assertFailed();
        $this->assertSame(0, User::where('personal_number', '7000111')->count());
        Storage::disk('minio')->assertExists(self::INBOX . '/car_booking_users.xlsx');
    }
}

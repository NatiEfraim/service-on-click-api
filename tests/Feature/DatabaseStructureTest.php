<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use Database\Seeders\ProdSeeder;
use Database\Seeders\ProdSeeders\BookingTypeHasRoleSeeder;
use Database\Seeders\ProdSeeders\BookingTypeHasStatusSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseStructureTest extends ManagementTestCase
{
    /** booking_type_id => name - the ids are the mobile app's, exported bookings carry them. */
    private const BOOKING_TYPES = [
        1 => 'car_booking',
        2 => 'car_maintenance_booking',
        3 => 'equipment_booking_male',
        4 => 'equipment_booking_female',
        5 => 'barber_booking',
        8 => 'transportation_booking',
        10 => 'out_unit_food_booking',
        11 => 'in_unit_food_booking',
        13 => 'construction_booking',
    ];

    public function test_the_six_roles_are_seeded_with_the_ids_of_the_role_enum(): void
    {
        $roles = DB::table('roles')->orderBy('id')->pluck('name', 'id')->all();

        $this->assertSame([
            RoleEnum::SUPER_ADMIN->value => 'super_admin',
            RoleEnum::ADMIN->value => 'admin',
            RoleEnum::APPROVER->value => 'approver',
            RoleEnum::VICE_APPROVER->value => 'vice_approver',
            RoleEnum::USER->value => 'user',
            RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value => 'transportation_control_officer',
        ], $roles);

        $this->assertSame(['passport'], DB::table('roles')->distinct()->pluck('guard_name')->all());
    }

    public function test_the_nine_booking_types_keep_the_ids_of_the_mobile_app(): void
    {
        $this->assertSame(
            self::BOOKING_TYPES,
            DB::table('booking_types')->orderBy('id')->pluck('name', 'id')->all()
        );
    }

    public function test_every_booking_type_has_an_admin_approver_vice_approver_and_user_permission(): void
    {
        $permissions = DB::table('permissions')->pluck('name')->all();

        foreach (array_keys(self::BOOKING_TYPES) as $bookingTypeId) {
            // The barber type (5) has no vice approver: its one step is answered by its approver.
            $prefixes = $bookingTypeId === 5 ? ['admin_', 'approver_', 'user_'] : ['admin_', 'approver_', 'vice_approver_', 'user_'];

            foreach ($prefixes as $prefix) {
                $this->assertContains($prefix . $bookingTypeId, $permissions);
            }
        }

        $this->assertNotContains('vice_approver_5', $permissions, 'no vice approver on the barber type');

        // Plus the control officer's, which only the transportation booking type (8) has; one vice approver permission fewer (the barber's).
        $this->assertContains('transportation_control_officer_8', $permissions);
        $this->assertCount(count(self::BOOKING_TYPES) * 4 + 1 - 1, $permissions);
    }

    public function test_the_super_admin_has_no_per_booking_type_permission(): void
    {
        $this->assertSame(
            0,
            DB::table('permissions')->where('name', 'like', 'super_admin%')->count()
        );
    }

    public function test_every_booking_type_is_linked_to_the_three_management_roles_and_the_user_role(): void
    {
        foreach (array_keys(self::BOOKING_TYPES) as $bookingTypeId) {
            $roleIds = DB::table('booking_type_has_roles')
                ->where('booking_type_id', $bookingTypeId)
                ->orderBy('role_id')
                ->pluck('role_id')
                ->map(fn($id) => (int) $id)
                ->all();

            // The route of the transportation booking starts at the control officer: it alone has that role as well.
            $expected = [RoleEnum::ADMIN->value, RoleEnum::APPROVER->value, RoleEnum::VICE_APPROVER->value, RoleEnum::USER->value];

            if ($bookingTypeId === 8) {
                $expected[] = RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value;
            }

            // The barber booking is answered by its approver in one step: it has no vice approver.
            if ($bookingTypeId === 5) {
                $expected = [RoleEnum::ADMIN->value, RoleEnum::APPROVER->value, RoleEnum::USER->value];
            }

            $this->assertSame($expected, $roleIds, 'booking type ' . $bookingTypeId);
        }
    }

    public function test_seeding_the_roles_of_the_types_again_drops_the_ones_a_type_no_longer_has(): void
    {
        // A database seeded before the barber route changed still links the vice approver to the barber type.
        DB::table('booking_type_has_roles')->insert(['booking_type_id' => 5, 'role_id' => RoleEnum::VICE_APPROVER->value, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(4, DB::table('booking_type_has_roles')->where('booking_type_id', 5)->count());

        $this->seed(BookingTypeHasRoleSeeder::class);

        $this->assertSame(
            [RoleEnum::ADMIN->value, RoleEnum::APPROVER->value, RoleEnum::USER->value],
            DB::table('booking_type_has_roles')->where('booking_type_id', 5)->orderBy('role_id')->pluck('role_id')->map(fn ($id): int => (int) $id)->all()
        );
        $this->assertSame(5, DB::table('booking_type_has_roles')->where('booking_type_id', 8)->count(), 'the other types keep their roles');
        $this->assertSame(36, DB::table('booking_type_has_roles')->count());
    }

    public function test_each_booking_type_can_reach_its_own_statuses(): void
    {
        $this->assertSame(18, DB::table('statuses')->count());

        $expectedCounts = [1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 5, 8 => 13, 10 => 8, 11 => 8, 13 => 8];

        foreach ($expectedCounts as $bookingTypeId => $count) {
            $this->assertSame(
                $count,
                DB::table('booking_type_has_statuses')->where('booking_type_id', $bookingTypeId)->count(),
                'booking type ' . $bookingTypeId
            );
        }
    }

    /** @return array<int, int> */
    private function statusIdsOf(int $bookingTypeId): array
    {
        return DB::table('booking_type_has_statuses')->where('booking_type_id', $bookingTypeId)->orderBy('status_id')->pluck('status_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** The barber route has one step - the barber's answer (11, 12) - plus the schedule change (13) and the requester's cancel (6): no statuses of the approver chain. */
    public function test_the_barber_booking_has_only_its_own_statuses(): void
    {
        $this->assertSame([1, 6, 11, 12, 13], $this->statusIdsOf(5));
        // This is what the barber's dashboard lists, so the vice approver's and the approver's statuses are not on it.
        $this->assertSame([], array_values(array_intersect($this->statusIdsOf(5), [2, 3, 4, 5, 7, 15, 16, 17, 18])));
        $this->assertSame([1, 3, 4, 5, 6, 7, 15, 16], $this->statusIdsOf(1), 'the standard route is untouched');
    }

    public function test_seeding_the_statuses_of_the_types_again_drops_the_ones_a_type_no_longer_has(): void
    {
        // A database seeded before the barber route changed still links the old statuses to the barber.
        DB::table('booking_type_has_statuses')->insert([
            ['booking_type_id' => 5, 'status_id' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['booking_type_id' => 5, 'status_id' => 15, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame([1, 3, 6, 11, 12, 13, 15], $this->statusIdsOf(5));

        $this->seed(BookingTypeHasStatusSeeder::class);

        $this->assertSame([1, 6, 11, 12, 13], $this->statusIdsOf(5));
        $this->assertSame(8, count($this->statusIdsOf(1)));
        $this->assertSame(13, count($this->statusIdsOf(8)));
    }

    /** The management layer's columns are part of the create-table migrations (the development database is rebuilt with run:all), and its tables exist. */
    public function test_the_management_columns_and_tables_exist(): void
    {
        $columns = [
            'bookings' => ['version', 'ext_status_id', 'status_source', 'status_changed_at'],
            'application_logs' => ['status_id', 'source', 'correlation_id'],
            'units' => ['internal_name'],
            'users' => ['department_id'],
            'form_fields' => ['version', 'position', 'is_active'],
            'option_fields' => ['is_active'],
        ];

        foreach ($columns as $table => $names) {
            $this->assertTrue(Schema::hasColumns($table, $names), "{$table} lacks one of: " . implode(', ', $names));
        }

        foreach (['departments', 'sla_targets', 'audit_log', 'audit_log_head', 'idempotency_keys', 'sync_files', 'sync_conflicts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "table {$table} is missing");
        }
    }

    public function test_the_production_seeders_can_run_again_without_duplicating_anything(): void
    {
        $tables = [
            'units', 'group_booking_types', 'booking_types', 'statuses', 'action_types', 'field_types',
            'form_fields', 'option_fields', 'system_references', 'system_reference_has_form_fields',
            'booking_type_has_statements', 'unit_has_booking_types', 'booking_type_has_statuses',
            'booking_type_has_roles', 'permissions', 'roles',
        ];

        $before = array_map(fn(string $table) => DB::table($table)->count(), $tables);

        $this->seed(ProdSeeder::class);

        $this->assertSame($before, array_map(fn(string $table) => DB::table($table)->count(), $tables));
    }
}

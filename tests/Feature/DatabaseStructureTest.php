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
    /** booking_type_id => name - ids 1-13 are the mobile app's (exported bookings carry them); 14-25 are web only. */
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
        14 => 'clearance_grant',
        15 => 'removable_media',
        16 => 'security_incident',
        20 => 'software_install',
        21 => 'printer_install',
        22 => 'email_fault',
        23 => 'user_rename',
        24 => 'user_open',
        25 => 'user_move',
    ];

    private const LEGACY_TYPE_IDS = [1, 2, 3, 4, 5, 8, 10, 11, 13];

    /** The permission prefixes each booking type has (= its roles in booking_type_has_roles). @return array<int, array<int, string>> */
    private function expectedPrefixes(): array
    {
        $expected = [];

        foreach (self::LEGACY_TYPE_IDS as $id) {
            $expected[$id] = $id === 5 ? ['admin_', 'approver_', 'user_'] : ['admin_', 'approver_', 'vice_approver_', 'user_'];
        }

        $expected[8][] = 'transportation_control_officer_';
        // The clearance grant has a route of its own: its requester, its owner and its coordinator - nothing of the approver chain, no plain user.
        $expected[14] = ['department_security_officer_', 'infosec_officer_', 'interview_coordinator_'];
        $expected[15] = $expected[16] = ['user_', 'infosec_officer_'];

        foreach ([20, 21, 22, 23, 24, 25] as $id) {
            $expected[$id] = ['user_', 'ict_handler_'];
        }

        return $expected;
    }

    public function test_the_ten_roles_are_seeded_with_the_ids_of_the_role_enum(): void
    {
        $roles = DB::table('roles')->orderBy('id')->pluck('name', 'id')->all();

        $this->assertSame([
            RoleEnum::SUPER_ADMIN->value => 'super_admin',
            RoleEnum::ADMIN->value => 'admin',
            RoleEnum::APPROVER->value => 'approver',
            RoleEnum::VICE_APPROVER->value => 'vice_approver',
            RoleEnum::USER->value => 'user',
            RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value => 'transportation_control_officer',
            RoleEnum::INFOSEC_OFFICER->value => 'infosec_officer',
            RoleEnum::ICT_HANDLER->value => 'ict_handler',
            RoleEnum::DEPARTMENT_SECURITY_OFFICER->value => 'department_security_officer',
            RoleEnum::INTERVIEW_COORDINATOR->value => 'interview_coordinator',
        ], $roles);

        $this->assertSame(['passport'], DB::table('roles')->distinct()->pluck('guard_name')->all());
    }

    public function test_the_booking_types_keep_their_ids(): void
    {
        $this->assertSame(
            self::BOOKING_TYPES,
            DB::table('booking_types')->orderBy('id')->pluck('name', 'id')->all()
        );
    }

    public function test_every_booking_type_has_exactly_the_permissions_of_its_roles(): void
    {
        $permissions = DB::table('permissions')->pluck('name')->all();
        $count = 0;

        foreach ($this->expectedPrefixes() as $bookingTypeId => $prefixes) {
            foreach ($prefixes as $prefix) {
                $this->assertContains($prefix . $bookingTypeId, $permissions);
                $count++;
            }
        }

        $this->assertNotContains('vice_approver_5', $permissions, 'no vice approver on the barber type');
        $this->assertNotContains('user_14', $permissions, 'the clearance grant is submitted by its own role, not the plain user');
        $this->assertNotContains('admin_14', $permissions, 'nothing of the approver chain on a route type');
        $this->assertSame(55, $count);
        $this->assertCount($count, $permissions);
    }

    public function test_the_super_admin_has_no_per_booking_type_permission(): void
    {
        $this->assertSame(
            0,
            DB::table('permissions')->where('name', 'like', 'super_admin%')->count()
        );
    }

    public function test_every_booking_type_is_linked_to_its_roles(): void
    {
        $roleIdByPrefix = [];

        foreach (RoleEnum::cases() as $role) {
            $roleIdByPrefix[$role->toPermissionEnum()->value] = $role->value;
        }

        foreach ($this->expectedPrefixes() as $bookingTypeId => $prefixes) {
            $expected = array_map(fn (string $prefix): int => $roleIdByPrefix[$prefix], $prefixes);
            sort($expected);

            $roleIds = DB::table('booking_type_has_roles')
                ->where('booking_type_id', $bookingTypeId)
                ->orderBy('role_id')
                ->pluck('role_id')
                ->map(fn($id) => (int) $id)
                ->all();

            $this->assertSame($expected, $roleIds, 'booking type ' . $bookingTypeId);
        }
    }

    public function test_seeding_the_roles_of_the_types_again_drops_the_ones_a_type_no_longer_has(): void
    {
        // A database seeded before the barber route changed still links the vice approver to the barber type; one seeded before the
        // clearance grant got its own route still links the plain user to it.
        DB::table('booking_type_has_roles')->insert([
            ['booking_type_id' => 5, 'role_id' => RoleEnum::VICE_APPROVER->value, 'created_at' => now(), 'updated_at' => now()],
            ['booking_type_id' => 14, 'role_id' => RoleEnum::USER->value, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame(4, DB::table('booking_type_has_roles')->where('booking_type_id', 5)->count());

        $this->seed(BookingTypeHasRoleSeeder::class);

        $this->assertSame(
            [RoleEnum::ADMIN->value, RoleEnum::APPROVER->value, RoleEnum::USER->value],
            DB::table('booking_type_has_roles')->where('booking_type_id', 5)->orderBy('role_id')->pluck('role_id')->map(fn ($id): int => (int) $id)->all()
        );
        $this->assertSame(
            [RoleEnum::INFOSEC_OFFICER->value, RoleEnum::DEPARTMENT_SECURITY_OFFICER->value, RoleEnum::INTERVIEW_COORDINATOR->value],
            DB::table('booking_type_has_roles')->where('booking_type_id', 14)->orderBy('role_id')->pluck('role_id')->map(fn ($id): int => (int) $id)->all()
        );
        $this->assertSame(5, DB::table('booking_type_has_roles')->where('booking_type_id', 8)->count(), 'the other types keep their roles');
        $this->assertSame(55, DB::table('booking_type_has_roles')->count());
    }

    public function test_each_booking_type_can_reach_its_own_statuses(): void
    {
        // 18 shared with the mobile app, 5 of the clearance grant route (101-105).
        $this->assertSame(23, DB::table('statuses')->count());
        $this->assertSame([101, 102, 103, 104, 105], DB::table('statuses')->where('id', '>=', 100)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(17, DB::table('action_types')->count());

        $expectedCounts = [1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 5, 8 => 13, 10 => 8, 11 => 8, 13 => 8, 14 => 6, 15 => 8, 20 => 8, 25 => 8];

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

    /** The clearance grant has a route of its own: its five steps and the refusal, nothing of the approver chain. */
    public function test_the_clearance_grant_has_only_the_statuses_of_its_route(): void
    {
        $this->assertSame([4, 101, 102, 103, 104, 105], $this->statusIdsOf(14));
        $this->assertSame([1, 3, 4, 5, 6, 7, 15, 16], $this->statusIdsOf(15), 'the other infosec types keep the standard route');
    }

    public function test_seeding_the_statuses_of_the_types_again_drops_the_ones_a_type_no_longer_has(): void
    {
        // A database seeded before the barber route changed still links the old statuses to the barber; one seeded before the clearance
        // grant got its route still links the generic statuses to it.
        DB::table('booking_type_has_statuses')->insert([
            ['booking_type_id' => 5, 'status_id' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['booking_type_id' => 5, 'status_id' => 15, 'created_at' => now(), 'updated_at' => now()],
            ['booking_type_id' => 14, 'status_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertSame([1, 3, 6, 11, 12, 13, 15], $this->statusIdsOf(5));

        $this->seed(BookingTypeHasStatusSeeder::class);

        $this->assertSame([1, 6, 11, 12, 13], $this->statusIdsOf(5));
        $this->assertSame([4, 101, 102, 103, 104, 105], $this->statusIdsOf(14));
        $this->assertSame(8, count($this->statusIdsOf(1)));
        $this->assertSame(13, count($this->statusIdsOf(8)));
    }

    /** The management layer's columns are part of the create-table migrations (the development database is rebuilt with run:all), and its tables exist. */
    public function test_the_management_columns_and_tables_exist(): void
    {
        $columns = [
            'bookings' => ['version', 'ext_status_id', 'parked_status_id', 'status_source', 'status_changed_at', 'handler_user_id', 'handler_assigned_at', 'stage_due_at'],
            'application_logs' => ['status_id', 'source', 'correlation_id'],
            'units' => ['internal_name'],
            'users' => ['department_id', 'is_exported_role'],
            'form_fields' => ['version', 'position', 'is_active', 'section', 'col_span', 'lookup_source', 'autofill_key'],
            'option_fields' => ['is_active', 'code'],
            'group_booking_types' => ['topic'],
            'booking_types' => ['description'],
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

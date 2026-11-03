<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use Database\Seeders\ProdSeeder;
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
            foreach (['admin_', 'approver_', 'vice_approver_', 'user_'] as $prefix) {
                $this->assertContains($prefix . $bookingTypeId, $permissions);
            }
        }

        // Plus the control officer's, which only the transportation booking type (8) has.
        $this->assertContains('transportation_control_officer_8', $permissions);
        $this->assertCount(count(self::BOOKING_TYPES) * 4 + 1, $permissions);
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

            $this->assertSame($expected, $roleIds, 'booking type ' . $bookingTypeId);
        }
    }

    public function test_each_booking_type_can_reach_its_own_statuses(): void
    {
        $this->assertSame(18, DB::table('statuses')->count());

        $expectedCounts = [1 => 8, 2 => 8, 3 => 8, 4 => 8, 5 => 11, 8 => 13, 10 => 8, 11 => 8, 13 => 8];

        foreach ($expectedCounts as $bookingTypeId => $count) {
            $this->assertSame(
                $count,
                DB::table('booking_type_has_statuses')->where('booking_type_id', $bookingTypeId)->count(),
                'booking type ' . $bookingTypeId
            );
        }
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

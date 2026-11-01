<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\FormField;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditService;

/** Units and departments, the people in them, which fields may be corrected, the audit log, and what an admin may grant. */
class ManagementAdminTest extends ManagementTestCase
{
    private const SUPER = '/api/bookings-super-admin-management';
    private const ADMIN_BASE = '/api/bookings-admin-management';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    //----------------------------- Org ---------------------------------------//

    public function test_the_super_admin_sees_the_units_and_adds_one_with_its_letter_and_name(): void
    {
        $units = $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/org')->assertOk()->json('data');
        $this->assertSame(['H', 'N', 'M', 'R'], array_column($units, 'code'));
        $this->assertSame('H', $units[0]['name'], 'a unit shows its letter until it is given a name');

        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'k', 'name' => 'כנף צפון'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'K')
            ->assertJsonPath('data.name', 'כנף צפון');

        $this->assertDatabaseHas('units', ['name' => 'K', 'internal_name' => 'כנף צפון']);
        $this->assertDatabaseHas('audit_log', ['action' => 'org.unit.create']);
    }

    public function test_a_unit_keeps_its_letter_and_only_its_name_changes(): void
    {
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/org/units/2', ['code' => 'N', 'name' => 'נשר'])
            ->assertOk()->assertJsonPath('data.name', 'נשר');

        $this->assertSame('N', Unit::find(2)->name, 'the letter is what the mobile app and UnitEnum know');

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/org/units/2', ['code' => 'Z', 'name' => 'נשר'])->assertUnprocessable();
        $this->assertSame('N', Unit::find(2)->name);
    }

    public function test_a_unit_letter_and_name_are_checked(): void
    {
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'H', 'name' => 'כנף'])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'K1', 'name' => 'כנף'])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'K', 'name' => 'א'])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'K', 'name' => 'נשר'])->assertCreated();
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'J', 'name' => 'נשר'])->assertUnprocessable();
    }

    public function test_only_the_super_admin_manages_the_organisation(): void
    {
        $this->as(self::ADMIN)->getJson(self::SUPER . '/org')->assertForbidden();
        $this->as(self::ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'K', 'name' => 'כנף'])->assertForbidden();
        $this->as(self::APPROVER)->postJson(self::SUPER . '/org/units/1/departments', ['name' => 'לוגיסטיקה'])->assertForbidden();
    }

    public function test_departments_are_added_renamed_and_switched_off_only_when_empty(): void
    {
        $id = $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units/1/departments', ['name' => 'לוגיסטיקה'])
            ->assertCreated()->json('data.id');

        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units/1/departments', ['name' => 'לוגיסטיקה'])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/org/departments/{$id}", ['name' => 'חינוך'])->assertOk()->assertJsonPath('data.name', 'חינוך');

        // A person in it keeps it from being switched off.
        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/users/' . User::where('personal_number', self::APPROVER)->value('uuid') . '/department', ['department_id' => $id])->assertOk();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/org/departments/{$id}", ['is_active' => false])->assertStatus(409);

        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/users/' . User::where('personal_number', self::APPROVER)->value('uuid') . '/department', ['department_id' => null])->assertOk();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/org/departments/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $org = $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/org')->json('data.0.departments');
        $this->assertSame('חינוך', $org[0]['name']);
        $this->assertSame(0, $org[0]['users']);
    }

    //----------------------------- People and departments --------------------//

    public function test_an_admin_puts_a_person_of_their_unit_in_a_department_of_that_unit(): void
    {
        $department = Department::create(['unit_id' => 1, 'name' => 'לוגיסטיקה', 'is_active' => true]);
        $other = Department::create(['unit_id' => 2, 'name' => 'מחשוב', 'is_active' => true]);
        $uuid = User::where('personal_number', self::VICE_APPROVER)->value('uuid');

        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . "/users/{$uuid}/department", ['department_id' => $department->id])
            ->assertOk()->assertJsonPath('data.department_name', 'לוגיסטיקה');

        $this->assertSame($department->id, User::where('uuid', $uuid)->value('department_id'));
        $this->assertDatabaseHas('audit_log', ['action' => 'user.department.change']);

        // A department of another unit is refused, and so is a person of another unit.
        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . "/users/{$uuid}/department", ['department_id' => $other->id])->assertUnprocessable();

        $stranger = User::factory()->create(['personal_number' => '1000090', 'unit_id' => 2]);
        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . "/users/{$stranger->uuid}/department", ['department_id' => $other->id])->assertForbidden();
        $this->as(self::SUPER_ADMIN)->putJson(self::ADMIN_BASE . "/users/{$stranger->uuid}/department", ['department_id' => $other->id])->assertOk();

        // The approver cannot reach the route.
        $this->as(self::APPROVER)->putJson(self::ADMIN_BASE . "/users/{$uuid}/department", ['department_id' => null])->assertForbidden();
    }

    public function test_a_user_is_created_in_a_department_and_the_list_shows_unit_and_department(): void
    {
        $department = Department::create(['unit_id' => 1, 'name' => 'לוגיסטיקה', 'is_active' => true]);

        $this->as(self::ADMIN)->postJson('/api/users', [
            'personal_number' => '3000009', 'full_name' => 'משתמש חדש', 'phone' => '0501234567', 'department_id' => $department->id,
        ])->assertCreated()->assertJsonPath('data.department_name', 'לוגיסטיקה')->assertJsonPath('data.unit_name', 'H');

        // A department of another unit is refused.
        $other = Department::create(['unit_id' => 2, 'name' => 'מחשוב', 'is_active' => true]);
        $this->as(self::ADMIN)->postJson('/api/users', [
            'personal_number' => '3000010', 'full_name' => 'משתמש אחר', 'department_id' => $other->id,
        ])->assertUnprocessable();

        $listed = $this->as(self::ADMIN)->getJson('/api/users/3000009')->assertOk()->json('data.0');
        $this->assertSame('לוגיסטיקה', $listed['department_name']);
        $this->assertDatabaseHas('audit_log', ['action' => 'user.create', 'entity_id' => '3000009']);
    }

    //----------------------------- Editable fields ---------------------------//

    public function test_the_super_admin_decides_which_date_and_time_fields_an_approver_may_correct(): void
    {
        // Field 2 is the start date of a car booking; it is editable as seeded.
        $field = FormField::findOrFail(2);
        $this->assertTrue($field->editable);

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/2/editable', ['editable' => false, 'version' => $field->version])
            ->assertOk()->assertJsonPath('data.editable', false)->assertJsonPath('data.version', $field->version + 1);

        $this->assertFalse(FormField::findOrFail(2)->editable);
        $this->assertDatabaseHas('audit_log', ['action' => 'config.form_field.editable']);

        // The version the screen saw is stale now.
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/2/editable', ['editable' => true, 'version' => $field->version])->assertStatus(409);
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/2/editable', ['editable' => true, 'version' => $field->version + 1])->assertOk();
    }

    public function test_only_a_date_or_a_time_can_be_made_editable_and_only_by_the_super_admin(): void
    {
        // Field 1 of the car booking is a text field.
        $text = FormField::query()->where('booking_type_id', 1)->whereNotIn('field_type_id', [3, 4])->firstOrFail();

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$text->id}/editable", ['editable' => true, 'version' => $text->version])
            ->assertUnprocessable();

        $this->as(self::ADMIN)->putJson(self::SUPER . '/form-fields/2/editable', ['editable' => false, 'version' => 1])->assertForbidden();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/99999/editable', ['editable' => false, 'version' => 1])->assertNotFound();
    }

    //----------------------------- Audit endpoints ---------------------------//

    public function test_the_super_admin_reads_and_filters_the_audit_log_and_checks_its_chain(): void
    {
        $this->as(self::SUPER_ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 24])->assertOk();
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'K', 'name' => 'כנף צפון'])->assertCreated();

        $all = $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit')->assertOk()->json();
        $this->assertSame(2, $all['meta']['total']);
        $this->assertSame('org.unit.create', $all['data'][0]['action'], 'newest first');
        $this->assertArrayNotHasKey('hash', $all['data'][0]);

        $this->assertSame(1, $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit?action=config.sla')->json('meta.total'));
        $this->assertSame(1, $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit?type=1')->json('meta.total'));
        $this->assertSame(0, $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit?result=denied')->json('meta.total'));
        $this->assertSame(0, $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit?from=' . now()->addDay()->toDateString())->json('meta.total'));
        $this->assertSame(2, $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit?from=' . now()->toDateString() . '&to=' . now()->toDateString())->json('meta.total'));

        $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit/verify')->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.checked', 2);

        $this->artisan('audit:verify')->assertExitCode(0);
    }

    public function test_nobody_else_reads_the_audit_log_and_nobody_changes_it(): void
    {
        $this->as(self::ADMIN)->getJson(self::SUPER . '/audit')->assertForbidden();
        $this->as(self::APPROVER)->getJson(self::SUPER . '/audit/verify')->assertForbidden();

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/audit/1', ['action' => 'x'])->assertStatus(405);
        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . '/audit/1')->assertStatus(405);

        $this->assertSame(2, AuditLog::where('action', 'audit.modify')->where('result', 'denied')->count());
        $this->assertTrue(app(AuditService::class)->verify()['valid']);
    }

    public function test_a_broken_chain_makes_the_command_fail(): void
    {
        $this->as(self::SUPER_ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 24])->assertOk();

        \DB::unprepared('DROP TRIGGER audit_log_no_update');
        \DB::table('audit_log')->update(['reason' => 'rewritten']);

        $this->artisan('audit:verify')->assertExitCode(1);
        $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit/verify')->assertOk()->assertJsonPath('data.valid', false);
    }

    //----------------------------- Granting roles ----------------------------//

    /** An admin (camp commander) of booking type 1 only, in unit H. */
    private function adminOfTypeOne(): void
    {
        $admin = User::factory()->create(['personal_number' => '1000097', 'unit_id' => 1]);
        $admin->assignRole('admin');
        $admin->givePermissionTo('admin_1');
    }

    public function test_granting_a_role_is_audited_with_what_the_person_could_do_before_and_after(): void
    {
        $this->as(self::ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', [
            'personal_number' => '2000009', 'full_name' => 'גורם חדש', 'reason' => 'מינוי חדש',
            'role_assignments' => [['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => false]],
        ])->assertOk();

        $audit = AuditLog::where('action', 'user.permissions.change')->firstOrFail();
        $this->assertSame('2000009', $audit->entity_id);
        $this->assertSame([], $audit->before['permissions']);
        $this->assertSame(['approver_1'], $audit->after['permissions']);
        $this->assertSame('מינוי חדש', $audit->reason);
        $this->assertSame(1, (int) $audit->unit_id);
    }

    public function test_an_admin_grants_only_on_the_booking_types_they_administer(): void
    {
        $this->adminOfTypeOne();

        $grant = fn (int $bookingTypeId) => $this->as('1000097')->postJson(self::ADMIN_BASE . '/assign-approver-role', [
            'personal_number' => '2000010', 'full_name' => 'גורם חדש',
            'role_assignments' => [['booking_type_id' => $bookingTypeId, 'is_approver' => true, 'is_vice_approver' => false]],
        ]);

        $grant(2)->assertForbidden();
        $this->assertDatabaseMissing('users', ['personal_number' => '2000010']);
        $this->assertDatabaseHas('audit_log', ['action' => 'user.permissions.change', 'result' => 'denied', 'reason' => 'outside_admin_scope']);

        $grant(1)->assertOk();
        $this->assertTrue(User::where('personal_number', '2000010')->firstOrFail()->hasPermissionTo('approver_1'));
    }

    public function test_an_admin_grants_only_to_the_people_of_their_own_unit_and_the_super_admin_to_anyone(): void
    {
        $stranger = User::factory()->create(['personal_number' => '1000091', 'unit_id' => 2]);
        $body = ['uuid' => $stranger->uuid, 'role_assignments' => [['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => false]]];

        $this->as(self::ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', $body)->assertForbidden();
        $this->assertFalse($stranger->fresh()->hasPermissionTo('approver_1'));

        $this->as(self::SUPER_ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', $body)->assertOk();
        $this->assertTrue($stranger->fresh()->hasPermissionTo('approver_1'));
    }

    public function test_taking_a_role_away_is_audited_too_and_a_no_op_is_not(): void
    {
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/assign-admin-role', [
            'personal_number' => '2000011', 'full_name' => 'מפקד חדש', 'unit_id' => 1,
            'role_assignments' => [['booking_type_id' => 1, 'is_admin' => true]],
        ])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'user.permissions.change')->count());

        $uuid = User::where('personal_number', '2000011')->value('uuid');
        $body = fn (bool $isAdmin) => ['uuid' => $uuid, 'role_assignments' => [['booking_type_id' => 1, 'is_admin' => $isAdmin]]];

        // The same again: nothing changed, nothing is written.
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/assign-admin-role', $body(true))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'user.permissions.change')->count());

        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/assign-admin-role', $body(false))->assertOk();
        $removed = AuditLog::where('action', 'user.permissions.change')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['admin_1'], $removed->before['permissions']);
        $this->assertSame([], $removed->after['permissions']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;

/**
 * Who an admin and the super admin may see and change: nobody deals with a super admin through the API, and an admin sees no admin
 * (the admins are the super admin's to manage). See User::unmanageableRoleNames().
 */
class ProtectedRolesTest extends ManagementTestCase
{
    private const ADMIN_BASE = '/api/bookings-admin-management';
    private const SUPER = '/api/bookings-super-admin-management';

    private const OTHER_ADMIN = '1000098';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** A second admin of the same unit as the admin of the tests, holding approver_1 as well (so the approver list could pick them up). */
    private function otherAdmin(): User
    {
        $other = User::factory()->create(['personal_number' => self::OTHER_ADMIN, 'unit_id' => 1, 'full_name' => 'מפקד מחנה נוסף']);
        $other->assignRole('admin');
        $other->givePermissionTo('admin_1');
        $other->assignRole('approver');
        $other->givePermissionTo('approver_1');

        return $other;
    }

    private function uuidOf(string $personalNumber): string
    {
        return User::where('personal_number', $personalNumber)->firstOrFail()->uuid;
    }

    /** @return array<int, string> The personal numbers GET /api/users[/identifier] returns (the whole list fits in one page). */
    private function listedBy(string $actor, string $identifier = ''): array
    {
        $response = $this->as($actor)->getJson('/api/users' . ($identifier === '' ? '' : '/' . $identifier))->assertOk();

        return collect($response->json('data'))->pluck('personal_number')->sort()->values()->all();
    }

    private function approverBody(string $uuid): array
    {
        return ['uuid' => $uuid, 'role_assignments' => [['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => false]]];
    }

    //----------------------------- The list ------------------------------------//

    public function test_an_admin_lists_neither_a_super_admin_nor_an_admin_not_even_themselves(): void
    {
        $this->otherAdmin();

        $listed = $this->listedBy(self::ADMIN);

        $this->assertEqualsCanonicalizing(
            [self::APPROVER, self::VICE_APPROVER, self::NO_ROLE, self::PLAIN_USER, self::OTHER_PLAIN_USER],
            $listed,
        );

        // Looking one up by number finds nothing, the same answer as for a person who does not exist.
        foreach ([self::SUPER_ADMIN, self::ADMIN, self::OTHER_ADMIN] as $hidden) {
            $this->assertSame([], $this->listedBy(self::ADMIN, $hidden), "admin looked up {$hidden}");
        }

        $this->assertSame([self::APPROVER], $this->listedBy(self::ADMIN, self::APPROVER));
    }

    public function test_the_super_admin_lists_the_admins_but_no_super_admin(): void
    {
        $this->otherAdmin();

        $listed = $this->listedBy(self::SUPER_ADMIN);

        $this->assertNotContains(self::SUPER_ADMIN, $listed);
        $this->assertContains(self::ADMIN, $listed);
        $this->assertContains(self::OTHER_ADMIN, $listed);
        $this->assertSame([self::ADMIN], $this->listedBy(self::SUPER_ADMIN, self::ADMIN));
        $this->assertSame([], $this->listedBy(self::SUPER_ADMIN, self::SUPER_ADMIN));
    }

    public function test_what_the_list_shows_of_the_others_is_unchanged(): void
    {
        $row = collect($this->as(self::ADMIN)->getJson('/api/users/' . self::APPROVER)->assertOk()->json('data'))->first();

        $this->assertSame(self::APPROVER, $row['personal_number']);
        $this->assertContains('approver', collect($row['roles'])->pluck('name')->all());
        $this->assertContains('approver_1', collect($row['permissions'])->pluck('name')->all());
    }

    public function test_the_approver_list_of_an_admin_skips_an_admin_who_is_also_an_approver(): void
    {
        $this->otherAdmin();

        $numbers = fn (string $actor): array => collect($this->as($actor)->getJson(self::ADMIN_BASE . '/get-approvers')->assertOk()->json('data'))
            ->pluck('personal_number')->all();

        $this->assertContains(self::APPROVER, $numbers(self::ADMIN));
        $this->assertNotContains(self::OTHER_ADMIN, $numbers(self::ADMIN));

        // The super admin manages admins, so they show for them.
        $this->assertContains(self::OTHER_ADMIN, $numbers(self::SUPER_ADMIN));
    }

    //----------------------------- Reading permissions -------------------------//

    public function test_the_permissions_of_a_super_admin_or_an_admin_are_not_shown_to_an_admin(): void
    {
        $this->otherAdmin();

        foreach ([self::SUPER_ADMIN, self::ADMIN, self::OTHER_ADMIN] as $hidden) {
            $this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/get-approver-permissions/' . $this->uuidOf($hidden))
                ->assertStatus(400);
        }

        $this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/get-approver-permissions/' . $this->uuidOf(self::APPROVER))->assertOk();
    }

    public function test_the_super_admin_reads_the_permissions_of_an_admin_but_not_of_a_super_admin(): void
    {
        $this->as(self::SUPER_ADMIN)->getJson(self::ADMIN_BASE . '/get-approver-permissions/' . $this->uuidOf(self::ADMIN))->assertOk();
        $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/get-admin-permissions/' . $this->uuidOf(self::ADMIN))->assertOk();

        $this->as(self::SUPER_ADMIN)->getJson(self::ADMIN_BASE . '/get-approver-permissions/' . $this->uuidOf(self::SUPER_ADMIN))->assertStatus(400);
        $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/get-admin-permissions/' . $this->uuidOf(self::SUPER_ADMIN))->assertStatus(400);
    }

    //----------------------------- Granting and replacing ----------------------//

    public function test_an_admin_cannot_grant_or_replace_anything_of_a_super_admin_or_another_admin(): void
    {
        $other = $this->otherAdmin();
        $before = $other->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();

        foreach ([self::SUPER_ADMIN, self::OTHER_ADMIN] as $hidden) {
            // By uuid and by personal number: the same person, the same refusal.
            $this->as(self::ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', $this->approverBody($this->uuidOf($hidden)))->assertForbidden();
            $this->as(self::ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', [
                'personal_number' => $hidden,
                'full_name' => 'x',
                'role_assignments' => [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => true]],
            ])->assertForbidden();
        }

        $this->assertSame($before, $other->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertFalse(User::where('personal_number', self::SUPER_ADMIN)->firstOrFail()->hasPermissionTo('approver_1'));
        $this->assertDatabaseHas('audit_log', ['action' => 'user.permissions.change', 'result' => 'denied', 'reason' => 'protected_role']);
        $this->assertDatabaseMissing('audit_log', ['action' => 'user.permissions.change', 'result' => 'success']);
    }

    public function test_taking_a_permission_away_from_an_admin_is_refused_to_an_admin_too(): void
    {
        $other = $this->otherAdmin();

        $this->as(self::ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', [
            'uuid' => $other->uuid,
            'role_assignments' => [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => false]],
        ])->assertForbidden();

        $this->assertTrue($other->fresh()->hasPermissionTo('approver_1'));
    }

    public function test_nobody_grants_anything_to_a_super_admin_not_even_a_super_admin(): void
    {
        $superAdmin = User::where('personal_number', self::SUPER_ADMIN)->firstOrFail();

        $this->as(self::SUPER_ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', $this->approverBody($superAdmin->uuid))->assertForbidden();
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/assign-admin-role', [
            'uuid' => $superAdmin->uuid,
            'role_assignments' => [['booking_type_id' => 1, 'is_admin' => true]],
        ])->assertForbidden();

        $this->assertSame(['super_admin'], $superAdmin->fresh()->getRoleNames()->all());
        $this->assertEmpty($superAdmin->fresh()->getDirectPermissions());
    }

    public function test_the_super_admin_still_changes_an_admin_and_an_admin_still_changes_an_approver(): void
    {
        $other = $this->otherAdmin();

        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/assign-admin-role', [
            'uuid' => $other->uuid,
            'role_assignments' => [['booking_type_id' => 2, 'is_admin' => true]],
        ])->assertOk();
        $this->assertTrue($other->fresh()->hasPermissionTo('admin_2'));

        $this->as(self::SUPER_ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', $this->approverBody($other->uuid))->assertOk();

        $this->as(self::ADMIN)->postJson(self::ADMIN_BASE . '/assign-approver-role', $this->approverBody($this->uuidOf(self::PLAIN_USER)))->assertOk();
        $this->assertTrue(User::where('personal_number', self::PLAIN_USER)->firstOrFail()->hasPermissionTo('approver_1'));
    }

    public function test_the_admin_list_of_the_super_admin_still_shows_the_admins(): void
    {
        $this->otherAdmin();

        $numbers = collect($this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/get-admins')->assertOk()->json('data'))->pluck('personal_number')->all();

        $this->assertEqualsCanonicalizing([self::ADMIN, self::OTHER_ADMIN], $numbers);
    }

    //----------------------------- Department ---------------------------------//

    public function test_the_department_of_a_super_admin_or_an_admin_is_not_changed_by_an_admin(): void
    {
        $this->otherAdmin();
        $department = Department::create(['unit_id' => 1, 'name' => 'לוגיסטיקה', 'is_active' => true]);

        foreach ([self::SUPER_ADMIN, self::OTHER_ADMIN] as $hidden) {
            $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/users/' . $this->uuidOf($hidden) . '/department', ['department_id' => $department->id])->assertForbidden();
            $this->assertNull(User::where('personal_number', $hidden)->value('department_id'));
        }

        $this->assertDatabaseHas('audit_log', ['action' => 'user.department.change', 'result' => 'denied', 'reason' => 'protected_role']);

        // The super admin puts an admin in a department; a super admin stays out of reach.
        $this->as(self::SUPER_ADMIN)->putJson(self::ADMIN_BASE . '/users/' . $this->uuidOf(self::OTHER_ADMIN) . '/department', ['department_id' => $department->id])->assertOk();
        $this->as(self::SUPER_ADMIN)->putJson(self::ADMIN_BASE . '/users/' . $this->uuidOf(self::SUPER_ADMIN) . '/department', ['department_id' => $department->id])->assertForbidden();
    }
}

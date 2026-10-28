<?php

namespace Tests\Feature;

use App\Models\User;

class RoleAssignmentTest extends ManagementTestCase
{
    public function test_a_super_admin_makes_a_new_person_an_admin_of_two_booking_types(): void
    {
        $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->postJson('/api/bookings-super-admin-management/assign-admin-role', [
                'personal_number' => '2000002',
                'full_name' => 'מפקד חדש',
                'unit_id' => 1,
                'role_assignments' => [
                    ['booking_type_id' => 1, 'is_admin' => true],
                    ['booking_type_id' => 2, 'is_admin' => true],
                    ['booking_type_id' => 3, 'is_admin' => false],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.personal_number', '2000002');

        $user = User::where('personal_number', '2000002')->firstOrFail();

        $this->assertSame(1, $user->unit_id);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertEqualsCanonicalizing(['admin_1', 'admin_2'], $user->getDirectPermissions()->pluck('name')->all());
    }

    public function test_taking_the_last_admin_permission_away_takes_the_admin_role_away_too(): void
    {
        $user = User::factory()->create(['personal_number' => '2000003', 'unit_id' => 1]);

        $assign = fn(bool $isAdmin) => $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->postJson('/api/bookings-super-admin-management/assign-admin-role', [
                'uuid' => $user->uuid,
                'role_assignments' => [['booking_type_id' => 1, 'is_admin' => $isAdmin]],
            ])
            ->assertOk();

        $assign(true);
        $this->assertTrue($user->fresh()->hasRole('admin'));

        $this->app['auth']->forgetGuards();

        $assign(false);
        $this->assertFalse($user->fresh()->hasRole('admin'));
        $this->assertEmpty($user->fresh()->getDirectPermissions());
    }

    public function test_an_admin_cannot_assign_admins(): void
    {
        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/bookings-super-admin-management/assign-admin-role', [
                'personal_number' => '2000004',
                'full_name' => 'x',
                'unit_id' => 1,
                'role_assignments' => [['booking_type_id' => 1, 'is_admin' => true]],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['personal_number' => '2000004']);
    }

    public function test_an_admin_makes_a_person_approver_of_one_type_and_vice_approver_of_another(): void
    {
        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'personal_number' => '2000005',
                'full_name' => 'גורם חדש',
                'role_assignments' => [
                    ['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => false],
                    ['booking_type_id' => 2, 'is_approver' => false, 'is_vice_approver' => true],
                ],
            ])
            ->assertOk();

        $user = User::where('personal_number', '2000005')->firstOrFail();

        // The new person joins the unit of the admin who added them.
        $this->assertSame(User::where('personal_number', self::ADMIN)->value('unit_id'), $user->unit_id);
        $this->assertTrue($user->hasRole('approver'));
        $this->assertTrue($user->hasRole('vice_approver'));
        $this->assertEqualsCanonicalizing(['approver_1', 'vice_approver_2'], $user->getDirectPermissions()->pluck('name')->all());

        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::ADMIN)
            ->getJson('/api/bookings-admin-management/get-approver-permissions/' . $user->uuid)
            ->assertOk()
            ->assertJsonPath('data.user.personal_number', '2000005');
    }

    public function test_the_assignment_needs_a_personal_number_of_seven_or_eight_digits(): void
    {
        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'personal_number' => '12',
                'full_name' => 'x',
                'role_assignments' => [['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => false]],
            ])
            ->assertUnprocessable();
    }

    public function test_an_admin_creates_a_user_in_their_own_unit(): void
    {
        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/users', [
                'personal_number' => '3000001',
                'full_name' => 'משתמש חדש',
                'phone' => '0501234567',
                'unit_id' => 4,
            ])
            ->assertCreated()
            ->assertJsonPath('data.personal_number', '3000001');

        // The unit sent by an admin is ignored: only a super admin picks one.
        $this->assertSame(
            User::where('personal_number', self::ADMIN)->value('unit_id'),
            User::where('personal_number', '3000001')->value('unit_id')
        );

        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/users', ['personal_number' => '3000001', 'full_name' => 'משתמש חדש'])
            ->assertUnprocessable();
    }
}

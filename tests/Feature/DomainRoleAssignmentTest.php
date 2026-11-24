<?php

namespace Tests\Feature;

use App\Enums\BookingTypeEnum;
use App\Models\User;

/**
 * The infosec and ICT booking types are handled by their own officer roles (infosec_officer, ict_handler) instead of the
 * approver chain: only the super admin grants them, through the same assign-approver-role screen (frontend-roles-permissions-guide.md).
 * The clearance grant (14) has three roles of its own: the קב"ט who submits, the במ (infosec officer) and the interview coordinator.
 */
class DomainRoleAssignmentTest extends ManagementTestCase
{
    private function infosecTypeId(): int
    {
        return (int) BookingTypeEnum::CLEARANCE_GRANT_BOOKING->getBookingTypeId();
    }

    private function ictTypeId(): int
    {
        return (int) BookingTypeEnum::SOFTWARE_INSTALL_BOOKING->getBookingTypeId();
    }

    public function test_a_super_admin_grants_and_revokes_the_domain_handler_roles(): void
    {
        $person = User::where('personal_number', self::NO_ROLE)->firstOrFail();

        // The rows carry exactly the flags of the roles the type has, as the roles screen sends them: three on the clearance grant, one on an ICT type.
        $assign = fn (bool $granted) => $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => $person->uuid,
                'reason' => 'אחראי ביטחון מידע ותקשוב',
                'role_assignments' => [
                    ['booking_type_id' => $this->infosecTypeId(), 'is_infosec_officer' => $granted, 'is_department_security_officer' => false, 'is_interview_coordinator' => $granted],
                    ['booking_type_id' => $this->ictTypeId(), 'is_ict_handler' => $granted],
                ],
            ])
            ->assertOk();

        $assign(true);

        $person = $person->fresh();
        $this->assertTrue($person->hasRole('infosec_officer'));
        $this->assertTrue($person->hasRole('interview_coordinator'));
        $this->assertTrue($person->hasRole('ict_handler'));
        $this->assertFalse($person->hasRole('department_security_officer'));
        $this->assertEqualsCanonicalizing(
            ['infosec_officer_' . $this->infosecTypeId(), 'interview_coordinator_' . $this->infosecTypeId(), 'ict_handler_' . $this->ictTypeId()],
            $person->getDirectPermissions()->pluck('name')->all()
        );

        $this->app['auth']->forgetGuards();

        $assign(false);

        $person = $person->fresh();
        $this->assertFalse($person->hasRole('infosec_officer'), 'the last permission of the role takes the role with it');
        $this->assertFalse($person->hasRole('interview_coordinator'));
        $this->assertFalse($person->hasRole('ict_handler'));
        $this->assertEmpty($person->getDirectPermissions());
    }

    public function test_the_flag_of_a_role_the_type_has_must_be_answered(): void
    {
        $person = User::where('personal_number', self::NO_ROLE)->firstOrFail();

        // An infosec type has no approver: its own flags are the ones that have to be answered.
        $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => $person->uuid,
                'role_assignments' => [['booking_type_id' => $this->infosecTypeId(), 'is_approver' => true]],
            ])
            ->assertUnprocessable();

        $this->app['auth']->forgetGuards();

        // On the clearance grant all three roles are answered: the officer's flag alone is not enough.
        $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => $person->uuid,
                'role_assignments' => [['booking_type_id' => $this->infosecTypeId(), 'is_infosec_officer' => true]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_assignments.0.is_department_security_officer', 'role_assignments.0.is_interview_coordinator']);

        $this->app['auth']->forgetGuards();

        // And a classic type still demands is_approver, as it always did.
        $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => $person->uuid,
                'role_assignments' => [['booking_type_id' => 1, 'is_vice_approver' => false]],
            ])
            ->assertUnprocessable();
    }

    public function test_an_admin_cannot_grant_the_domain_handler_roles(): void
    {
        $person = User::where('personal_number', self::NO_ROLE)->firstOrFail();

        // No admin_{id} permission exists for an infosec/ICT type, so they are never among an admin's administered types.
        $this->actingAsPersonalNumber(self::ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => $person->uuid,
                'role_assignments' => [['booking_type_id' => $this->infosecTypeId(), 'is_infosec_officer' => true, 'is_department_security_officer' => false, 'is_interview_coordinator' => false]],
            ])
            ->assertForbidden();

        $this->assertFalse($person->fresh()->hasRole('infosec_officer'));
    }

    public function test_the_super_admin_matrix_offers_only_the_domain_flags_for_a_domain_type(): void
    {
        $person = User::where('personal_number', self::NO_ROLE)->firstOrFail();

        $response = $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->getJson('/api/bookings-admin-management/get-approver-permissions/' . $person->uuid)
            ->assertOk();

        $permissions = collect($response->json('data.permissions'));
        $roleOptions = $response->json('data.role_options');

        $this->assertSame(\App\Enums\RoleEnum::DEPARTMENT_SECURITY_OFFICER->value, $roleOptions['department_security_officer']);
        $this->assertSame(\App\Enums\RoleEnum::INTERVIEW_COORDINATOR->value, $roleOptions['interview_coordinator']);

        $infosec = $permissions->firstWhere('booking_type_id', $this->infosecTypeId());
        $media = $permissions->firstWhere('booking_type_id', (int) BookingTypeEnum::REMOVABLE_MEDIA_BOOKING->getBookingTypeId());
        $ict = $permissions->firstWhere('booking_type_id', $this->ictTypeId());
        $car = $permissions->firstWhere('booking_type_id', 1);

        $this->assertNotNull($infosec, 'the super admin sees the infosec types');
        $this->assertArrayHasKey('is_infosec_officer', $infosec);
        $this->assertArrayHasKey('is_department_security_officer', $infosec, 'the clearance grant has its own requester role');
        $this->assertArrayHasKey('is_interview_coordinator', $infosec);
        $this->assertArrayNotHasKey('is_approver', $infosec, 'an infosec type has no approver');
        $this->assertArrayNotHasKey('is_vice_approver', $infosec);

        $this->assertArrayHasKey('is_infosec_officer', $media);
        $this->assertArrayNotHasKey('is_interview_coordinator', $media, 'the coordinator belongs to the clearance grant only');

        $this->assertNotNull($ict, 'the super admin sees the ICT types');
        $this->assertArrayHasKey('is_ict_handler', $ict);
        $this->assertArrayNotHasKey('is_approver', $ict);

        $this->assertNotNull($car, 'the super admin sees the classic types too');
        $this->assertArrayHasKey('is_approver', $car);

        $this->app['auth']->forgetGuards();

        // An admin's matrix still holds only their own administered types: no infosec/ICT rows.
        $adminPermissions = collect(
            $this->actingAsPersonalNumber(self::ADMIN)
                ->getJson('/api/bookings-admin-management/get-approver-permissions/' . $person->uuid)
                ->assertOk()
                ->json('data.permissions')
        );

        $this->assertNull($adminPermissions->firstWhere('booking_type_id', $this->infosecTypeId()));
        $this->assertNotNull($adminPermissions->firstWhere('booking_type_id', 1));
    }
}

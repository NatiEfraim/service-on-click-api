<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * A "{role}_{booking type}" permission is a real grant only where the booking type HAS the role (booking_type_has_roles). A stray one
 * (infosec_officer_1: the shared cars have no infosec officer; the frontend found two in a database) opens and manages nothing, cannot be
 * granted through the roles screens, and is removed by permissions:prune-invalid.
 */
class PermissionRulesTest extends ManagementTestCase
{
    private const BASE = '/api/bookings-approver-management';
    private const ADMIN_BASE = '/api/bookings-admin-management';
    private const SUPER = '/api/bookings-super-admin-management';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDomainPeople();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** What an old seeder run or a manual grant left behind: the permission and a person holding it. */
    private function stray(string $personalNumber, string ...$names): User
    {
        $user = User::where('personal_number', $personalNumber)->firstOrFail();

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'passport');
        }

        $user->givePermissionTo($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @return array<int, int> */
    private function catalogTypeIds(string $personalNumber): array
    {
        $catalog = $this->as($personalNumber)->getJson('/api/requester/catalog')->assertOk()->json('data');
        $ids = [];

        foreach ($catalog['topics'] ?? [] as $topic) {
            foreach ($topic['groups'] ?? [] as $group) {
                foreach ($group['booking_types'] ?? [] as $type) {
                    $ids[] = (int) $type['id'];
                }
            }
        }

        foreach ($catalog['domains'] ?? [] as $type) {
            $ids[] = (int) $type['id'];
        }

        sort($ids);

        return $ids;
    }

    //----------------------------- Reading: a stray permission counts for nothing --//

    public function test_a_permission_of_a_role_the_type_does_not_have_opens_and_manages_nothing(): void
    {
        $before = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $catalogBefore = $this->catalogTypeIds(self::INFOSEC_OFFICER);

        // The two the frontend found, and one that would manage an ICT type as an approver.
        $this->stray(self::INFOSEC_OFFICER, 'infosec_officer_1', 'infosec_officer_2', 'approver_20');

        $after = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/me')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing($before['managed_booking_types'], $after['managed_booking_types']);
        $this->assertEqualsCanonicalizing($before['dashboard_booking_types'], $after['dashboard_booking_types']);
        $this->assertEqualsCanonicalizing($before['permissions'], $after['permissions'], 'the screens are not told of a grant that means nothing');
        $this->assertNotContains(1, $after['managed_booking_types']);
        $this->assertNotContains(20, $after['managed_booking_types']);

        // Nothing to open either: not the cars (1, 2).
        $this->assertSame($catalogBefore, $this->catalogTypeIds(self::INFOSEC_OFFICER));
        $this->as(self::INFOSEC_OFFICER)->getJson('/api/requester/form/1')->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->postJson('/api/bookings/2', [])->assertForbidden();

        // And a car request is not in their queue: the type is not theirs.
        $car = Booking::create([
            'serial_number' => 800002001, 'unit_id' => 1, 'booking_type_id' => 1, 'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => 1, 'status_source' => 'external', 'status_changed_at' => now(), 'version' => 1,
        ]);
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings/' . $car->uuid)->assertNotFound();
    }

    public function test_a_valid_permission_still_counts_and_the_login_lists_only_valid_ones(): void
    {
        $this->stray(self::INFOSEC_OFFICER, 'infosec_officer_1');

        $response = $this->postJson('/api/login', ['personal_number' => self::INFOSEC_OFFICER])->assertCreated();

        $this->assertNotContains('infosec_officer_1', $response->json('permissions'));
        $this->assertContains('infosec_officer_15', $response->json('permissions'));
    }

    //----------------------------- Writing: the roles screens refuse it -------------//

    private function assign(string $actor, User $person, array $assignments): \Illuminate\Testing\TestResponse
    {
        return $this->as($actor)->postJson(self::ADMIN_BASE . '/assign-approver-role', ['uuid' => $person->uuid, 'role_assignments' => $assignments]);
    }

    public function test_granting_a_role_the_type_does_not_have_is_refused_like_every_other_flag_rule(): void
    {
        $person = User::where('personal_number', self::NO_ROLE)->firstOrFail();
        $barber = \App\Enums\BookingTypeEnum::BARBER_BOOKING->getBookingTypeId();
        $infosec = \App\Enums\BookingTypeEnum::REMOVABLE_MEDIA_BOOKING->getBookingTypeId();
        $cars = ['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => false];

        // The infosec officer on the cars; the control officer on the cars; the vice approver on the barber type; the approver on an infosec type.
        $this->assign(self::SUPER_ADMIN, $person, [[...$cars, 'is_infosec_officer' => true]])->assertUnprocessable();
        // ict_handler is a role no type has any more (ICT runs on the service desk route): its flag is unknown to the screen and ignored.
        $this->assign(self::SUPER_ADMIN, $person, [[...$cars, 'is_ict_handler' => true]])->assertOk();
        $this->assign(self::SUPER_ADMIN, $person, [[...$cars, 'is_transportation_control_officer' => true]])->assertUnprocessable();
        $this->assign(self::SUPER_ADMIN, $person, [['booking_type_id' => $infosec, 'is_infosec_officer' => false, 'is_approver' => true]])->assertUnprocessable();

        $this->assertEmpty($person->fresh()->getDirectPermissions(), 'nothing was written');

        // The one documented exception: a vice approver flag sent for the barber type is ignored (BarberBookingRouteTest), and writes nothing.
        $this->assign(self::SUPER_ADMIN, $person, [['booking_type_id' => $barber, 'is_approver' => false, 'is_vice_approver' => true]])->assertOk();
        $this->assertEmpty($person->fresh()->getDirectPermissions(), 'the ignored flag wrote nothing');

        // Taking a role away that the type does not have is harmless and stays ignored (the barber contract says so).
        $this->assign(self::SUPER_ADMIN, $person, [['booking_type_id' => $barber, 'is_approver' => false, 'is_vice_approver' => false]])->assertOk();
        $this->assign(self::SUPER_ADMIN, $person, [[...$cars, 'is_infosec_officer' => false]])->assertOk();

        // What the type does have is still granted.
        $this->assign(self::SUPER_ADMIN, $person, [['booking_type_id' => $infosec, 'is_infosec_officer' => true]])->assertOk();
        $this->assertContains('infosec_officer_' . $infosec, $person->fresh()->getDirectPermissions()->pluck('name')->all());
    }

    public function test_the_admin_role_cannot_be_granted_on_a_type_that_does_not_have_it(): void
    {
        $person = User::where('personal_number', self::NO_ROLE)->firstOrFail();
        $infosec = \App\Enums\BookingTypeEnum::REMOVABLE_MEDIA_BOOKING->getBookingTypeId();

        // Even when a stray admin_15 permission exists in the database (an old seeder run), the screen cannot hand it out.
        Permission::findOrCreate('admin_' . $infosec, 'passport');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/assign-admin-role', ['uuid' => $person->uuid, 'role_assignments' => [['booking_type_id' => $infosec, 'is_admin' => true]]])
            ->assertUnprocessable();
        $this->assertEmpty($person->fresh()->getDirectPermissions());

        // A type that has the admin role is unchanged.
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/assign-admin-role', ['uuid' => $person->uuid, 'role_assignments' => [['booking_type_id' => 1, 'is_admin' => true]]])
            ->assertOk();
        $this->assertContains('admin_1', $person->fresh()->getDirectPermissions()->pluck('name')->all());
    }

    //----------------------------- The cleanup command ------------------------------//

    public function test_the_prune_command_lists_first_and_removes_only_with_apply(): void
    {
        $this->artisan('permissions:prune-invalid')->expectsOutputToContain('No invalid permission')->assertExitCode(0);

        $user = $this->stray(self::INFOSEC_OFFICER, 'infosec_officer_1', 'infosec_officer_2');

        $this->artisan('permissions:prune-invalid')
            ->expectsOutputToContain(self::INFOSEC_OFFICER . ': infosec_officer_1, infosec_officer_2')
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(0);

        $this->assertContains('infosec_officer_1', $user->fresh()->getDirectPermissions()->pluck('name')->all(), 'a dry run changes nothing');

        $this->artisan('permissions:prune-invalid --apply')->expectsOutputToContain('Removed')->assertExitCode(0);

        $names = $user->fresh()->getDirectPermissions()->pluck('name')->all();
        $this->assertNotContains('infosec_officer_1', $names);
        $this->assertNotContains('infosec_officer_2', $names);
        $this->assertContains('infosec_officer_15', $names, 'the real grants stay');
        $this->assertNull(Permission::where('name', 'infosec_officer_1')->first(), 'the stale definition goes with it');

        $audit = AuditLog::where('action', 'user.permissions.change')->latest('id')->firstOrFail();
        $this->assertContains('infosec_officer_1', $audit->before['permissions']);
        $this->assertNotContains('infosec_officer_1', $audit->after['permissions']);
        $this->assertSame('automation', $audit->source);

        // Idempotent.
        $this->artisan('permissions:prune-invalid --apply')->expectsOutputToContain('No invalid permission')->assertExitCode(0);
    }
}

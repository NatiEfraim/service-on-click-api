<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\ProdSeeder;
use Database\Seeders\TestSeeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ResetUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDED_USER = '99810738'; // an admin in the UserSeeder

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProdSeeder::class);
        $this->seed(UserSeeder::class);
    }

    public function test_it_removes_stale_assignments_and_keeps_the_users(): void
    {
        $user = User::where('personal_number', self::SEEDED_USER)->firstOrFail();
        $strayUser = User::factory()->create(['personal_number' => '3000001']);

        // The seeder only ever adds: a role changed in the file leaves the old one behind.
        $user->assignRole(RoleEnum::SUPER_ADMIN->value);
        $user->givePermissionTo('approver_1');
        DB::table('role_has_permissions')->insert([
            'permission_id' => DB::table('permissions')->value('id'),
            'role_id' => RoleEnum::USER->value,
        ]);

        $this->artisan('users:reset')->assertSuccessful();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();

        $this->assertSame(['admin'], $user->getRoleNames()->all());
        $this->assertFalse($user->hasDirectPermission('approver_1'));
        $this->assertTrue($user->hasDirectPermission('admin_1'));
        $this->assertSame(0, DB::table('role_has_permissions')->count());
        $this->assertNotNull($strayUser->fresh());
    }

    public function test_a_person_who_exists_by_id_number_only_gets_the_personal_number_instead_of_a_duplicate(): void
    {
        $existing = User::where('personal_number', self::SEEDED_USER)->firstOrFail();
        $existing->update(['personal_number' => null]); // as if imported from the mobile app
        $countBefore = User::count();

        $this->artisan('users:reset')->assertSuccessful();

        $this->assertSame($countBefore, User::count());
        $this->assertSame(self::SEEDED_USER, $existing->fresh()->personal_number);
    }

    public function test_a_failing_seeder_leaves_the_assignments_as_they_were(): void
    {
        // The ID number the seeder gives to 99815080 is already held by somebody else: the seeding cannot succeed.
        User::where('personal_number', '99815080')->update(['personal_id' => '111111111']);
        User::factory()->create(['personal_number' => '3000009', 'personal_id' => '325639797']);
        $rolesBefore = DB::table('model_has_roles')->count();

        $this->artisan('users:reset')->assertFailed();

        $this->assertSame($rolesBefore, DB::table('model_has_roles')->count());
    }

    public function test_wipe_users_deletes_everyone_who_is_not_in_the_seeder(): void
    {
        $strayUser = User::factory()->create(['personal_number' => '3000001']);
        $seededCount = User::count() - 1;

        $this->artisan('users:reset', ['--force' => true, '--wipe-users' => true])->assertSuccessful();

        $this->assertNull($strayUser->fresh());
        $this->assertSame($seededCount, User::count());
        $this->assertTrue(User::where('personal_number', self::SEEDED_USER)->firstOrFail()->hasRole('admin'));
    }

    public function test_wipe_users_does_nothing_without_force(): void
    {
        $strayUser = User::factory()->create(['personal_number' => '3000001']);

        $this->artisan('users:reset', ['--wipe-users' => true])->assertFailed();

        $this->assertNotNull($strayUser->fresh());
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('users:reset', ['--force' => true, '--wipe-users' => true])->assertFailed();

        $this->assertGreaterThan(0, User::count());
    }
}

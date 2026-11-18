<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingTypeHasRole;
use App\Models\User;
use Database\Seeders\ProdSeeder;
use Database\Seeders\TestSeeders\DevBookingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The production structure plus the people the tests log in as (made here, so the tests do not depend on the
 * development UserSeeder) and the synthetic bookings of DevBookingSeeder, which belong to the two plain users.
 *
 * Log in by personal number: 1000001 super admin, 1000002 admin, 1000003 approver, 1000004 vice approver,
 * 1000005 a person with no role, 1000006 and 1000007 plain users (only the user role).
 * The people of the clearance grant route (קב"ט 1000020, במ 1000021, מתאם תחקיר 1000022) are made on demand by
 * createDomainPeople(), so the counts of the older tests stay as they were.
 */
abstract class ManagementTestCase extends TestCase
{
    use RefreshDatabase;

    protected const SUPER_ADMIN = '1000001';
    protected const ADMIN = '1000002';
    protected const APPROVER = '1000003';
    protected const VICE_APPROVER = '1000004';
    protected const NO_ROLE = '1000005';
    protected const PLAIN_USER = '1000006';
    protected const OTHER_PLAIN_USER = '1000007';

    // The clearance grant route (type 14).
    protected const SECURITY_OFFICER = '1000020';
    protected const INFOSEC_OFFICER = '1000021';
    protected const INTERVIEW_COORDINATOR = '1000022';

    protected const TOKEN_NAME = 'TestAccessToken';

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.access_token_name' => self::TOKEN_NAME]);

        // The Passport keys are not in git: create them on the machines that have none yet.
        if (!is_file(storage_path('oauth-private.key')) || !is_file(storage_path('oauth-public.key'))) {
            Artisan::call('passport:keys', ['--force' => true]);
        }

        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => self::TOKEN_NAME,
            '--provider' => 'users',
        ]);

        $this->seed(ProdSeeder::class);

        $this->createPeople();

        $this->seed(DevBookingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * One person per role, as the seeded system has them: a role and, except for the super admin, "{role}_{booking type}" permissions - for the
     * booking types that have the role (the barber type has no vice approver, so nobody gets vice_approver_5).
     */
    private function createPeople(): void
    {
        $people = [
            self::SUPER_ADMIN => RoleEnum::SUPER_ADMIN,
            self::ADMIN => RoleEnum::ADMIN,
            self::APPROVER => RoleEnum::APPROVER,
            self::VICE_APPROVER => RoleEnum::VICE_APPROVER,
            self::PLAIN_USER => RoleEnum::USER,
            self::OTHER_PLAIN_USER => RoleEnum::USER,
        ];

        foreach ($people as $personalNumber => $roleEnum) {
            $this->createPerson($personalNumber, $roleEnum);
        }

        // A person with no role at all.
        User::factory()->create(['personal_number' => self::NO_ROLE, 'unit_id' => UnitEnum::H->value]);
    }

    /** A person of unit H with one role and that role's permission on every booking type that has the role. */
    protected function createPerson(string $personalNumber, RoleEnum $roleEnum, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + [
            'personal_number' => $personalNumber,
            'unit_id' => UnitEnum::H->value,
        ]);

        $user->assignRole($roleEnum->value);

        if ($roleEnum !== RoleEnum::SUPER_ADMIN) {
            $user->givePermissionTo(
                BookingTypeHasRole::query()->where('role_id', $roleEnum->value)->pluck('booking_type_id')
                    ->map(fn ($id): string => $roleEnum->toPermissionEnum()->keyForId((int) $id))
                    ->all()
            );
        }

        return $user;
    }

    /** The three people of the clearance grant route: the קב"ט who submits, the במ who owns the route and the coordinator who schedules. */
    protected function createDomainPeople(): void
    {
        foreach ([
            self::SECURITY_OFFICER => RoleEnum::DEPARTMENT_SECURITY_OFFICER,
            self::INFOSEC_OFFICER => RoleEnum::INFOSEC_OFFICER,
            self::INTERVIEW_COORDINATOR => RoleEnum::INTERVIEW_COORDINATOR,
        ] as $personalNumber => $roleEnum) {
            if (!User::where('personal_number', $personalNumber)->exists()) {
                // With an ID number (300000020..22), so the users file could list them if it ever wrongly did.
                $this->createPerson($personalNumber, $roleEnum, ['personal_id' => '300' . substr($personalNumber, 1)]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A bearer token for the user with this personal number. */
    protected function tokenFor(string $personalNumber): string
    {
        return User::where('personal_number', $personalNumber)->firstOrFail()
            ->createToken(self::TOKEN_NAME)->accessToken;
    }

    /** Send requests with the token in the cookie, as the browser does (the test client sends no cookies on JSON requests without credentials). */
    protected function withCookieToken(string $token): static
    {
        return $this->withUnencryptedCookie(self::TOKEN_NAME, $token)->withCredentials();
    }

    /** Send requests as the user with this personal number. */
    protected function actingAsPersonalNumber(string $personalNumber): static
    {
        return $this->withToken($this->tokenFor($personalNumber));
    }
}

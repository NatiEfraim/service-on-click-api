<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingType;
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

    /** One person per role, as the seeded system has them: a role and, except for the super admin, "{role}_{booking type}" permissions. */
    private function createPeople(): void
    {
        $bookingTypeIds = BookingType::query()->pluck('id');

        $people = [
            self::SUPER_ADMIN => RoleEnum::SUPER_ADMIN,
            self::ADMIN => RoleEnum::ADMIN,
            self::APPROVER => RoleEnum::APPROVER,
            self::VICE_APPROVER => RoleEnum::VICE_APPROVER,
            self::PLAIN_USER => RoleEnum::USER,
            self::OTHER_PLAIN_USER => RoleEnum::USER,
        ];

        foreach ($people as $personalNumber => $roleEnum) {
            $user = User::factory()->create([
                'personal_number' => $personalNumber,
                'unit_id' => UnitEnum::H->value,
            ]);

            $user->assignRole($roleEnum->value);

            if ($roleEnum !== RoleEnum::SUPER_ADMIN) {
                $user->givePermissionTo(
                    $bookingTypeIds->map(fn($id): string => $roleEnum->toPermissionEnum()->keyForId((int) $id))->all()
                );
            }
        }

        // A person with no role at all.
        User::factory()->create(['personal_number' => self::NO_ROLE, 'unit_id' => UnitEnum::H->value]);
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

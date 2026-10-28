<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The seeded system: the production structure plus the synthetic users of the testing seeders.
 * Log in as one of them by personal number: 1000001 super admin, 1000002 admin, 1000003 approver,
 * 1000004 vice approver, 1000005 a person with no role.
 */
abstract class ManagementTestCase extends TestCase
{
    use RefreshDatabase;

    protected const SUPER_ADMIN = '1000001';
    protected const ADMIN = '1000002';
    protected const APPROVER = '1000003';
    protected const VICE_APPROVER = '1000004';
    protected const NO_ROLE = '1000005';

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

        $this->seed(DatabaseSeeder::class);

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

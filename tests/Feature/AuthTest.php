<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\User;

class AuthTest extends ManagementTestCase
{
    public function test_a_request_without_a_token_gets_a_json_401(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);
    }

    public function test_login_needs_a_personal_number_of_seven_or_eight_digits(): void
    {
        $this->postJson('/api/login')->assertBadRequest();
        $this->postJson('/api/login', ['personal_number' => '123'])->assertBadRequest();
        $this->postJson('/api/login', ['personal_number' => '123456789'])->assertBadRequest();
        $this->postJson('/api/login', ['personal_number' => 'abcdefg'])->assertBadRequest();
    }

    public function test_login_of_an_unknown_personal_number_is_refused(): void
    {
        $this->postJson('/api/login', ['personal_number' => '7654321'])->assertNotFound();
    }

    public function test_login_of_a_deleted_user_is_refused(): void
    {
        User::factory()->deleted()->create(['personal_number' => '7000001']);

        $this->postJson('/api/login', ['personal_number' => '7000001'])->assertNotFound();
    }

    public function test_login_sets_the_token_cookie_and_answers_with_the_role_and_permissions(): void
    {
        $response = $this->postJson('/api/login', ['personal_number' => self::APPROVER])
            ->assertCreated()
            ->assertJsonPath('personal_number', self::APPROVER)
            ->assertJsonPath('role', ['approver']);

        $this->assertContains('approver_1', $response->json('permissions'));
        $this->assertNotNull($response->getCookie(self::TOKEN_NAME, false), 'the token cookie is set');
    }

    public function test_the_cookie_alone_authenticates_the_following_requests(): void
    {
        $cookie = $this->postJson('/api/login', ['personal_number' => self::ADMIN])
            ->assertCreated()
            ->getCookie(self::TOKEN_NAME, false)
            ->getValue();

        $this->app['auth']->forgetGuards();

        $this->withCookieToken($cookie)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.personal_number', self::ADMIN)
            ->assertJsonPath('data.roles.0.display_name', RoleEnum::ADMIN->getPermissionDisplayName());
    }

    public function test_the_user_endpoint_sends_only_the_name_and_the_role_label(): void
    {
        $response = $this->actingAsPersonalNumber(self::ADMIN)->getJson('/api/user')->assertOk();

        // What the requester home reads; no ID number, phone, unit, department or permission list.
        $this->assertSame(['name', 'personal_number', 'roles'], array_keys($response->json('data')));
        $this->assertSame([['display_name' => RoleEnum::ADMIN->getPermissionDisplayName()]], $response->json('data.roles'));
        $this->assertSame(User::where('personal_number', self::ADMIN)->value('full_name'), $response->json('data.name'));
    }

    public function test_logout_revokes_the_token_and_forgets_the_cookie(): void
    {
        $cookie = $this->postJson('/api/login', ['personal_number' => self::ADMIN])
            ->getCookie(self::TOKEN_NAME, false)
            ->getValue();

        $this->app['auth']->forgetGuards();

        $logout = $this->withCookieToken($cookie)->postJson('/api/logout')->assertOk();
        $this->assertTrue($logout->getCookie(self::TOKEN_NAME, false)->isCleared(), 'the cookie is expired');

        $this->app['auth']->forgetGuards();

        $this->withCookieToken($cookie)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_a_new_login_revokes_the_token_of_the_earlier_one(): void
    {
        $first = $this->postJson('/api/login', ['personal_number' => self::VICE_APPROVER])
            ->getCookie(self::TOKEN_NAME, false)
            ->getValue();
        $second = $this->postJson('/api/login', ['personal_number' => self::VICE_APPROVER])
            ->getCookie(self::TOKEN_NAME, false)
            ->getValue();

        $this->app['auth']->forgetGuards();
        $this->withCookieToken($first)->getJson('/api/user')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withCookieToken($second)->getJson('/api/user')->assertOk();
    }

    public function test_a_person_with_no_role_logs_in_as_a_plain_user(): void
    {
        $this->assertFalse(User::where('personal_number', self::NO_ROLE)->first()->hasAnyRole(['user', 'admin']));

        $response = $this->postJson('/api/login', ['personal_number' => self::NO_ROLE])
            ->assertCreated()
            ->assertJsonPath('role', ['user']);

        // The plain user's role comes with what it needs to open a request: user_{id} for the types their unit offers a plain user (not the clearance grant).
        $permissions = $response->json('permissions');
        $this->assertContains('user_1', $permissions);
        $this->assertNotContains('user_14', $permissions);
        $this->assertSame([], array_values(array_filter($permissions, fn (string $name): bool => !str_starts_with($name, 'user_'))));

        $this->assertTrue(User::where('personal_number', self::NO_ROLE)->first()->hasRole('user'));

        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::NO_ROLE)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.roles.0.display_name', RoleEnum::USER->getPermissionDisplayName());
    }

    public function test_a_manager_whose_role_was_taken_away_logs_in_as_a_plain_user(): void
    {
        $approver = User::where('personal_number', self::APPROVER)->firstOrFail();
        $approver->syncPermissions([]);
        $approver->removeRole('approver');

        $this->postJson('/api/login', ['personal_number' => self::APPROVER])
            ->assertCreated()
            ->assertJsonPath('role', ['user']);
    }

    public function test_a_manager_keeps_the_manager_role_and_does_not_get_the_user_role(): void
    {
        $this->postJson('/api/login', ['personal_number' => self::ADMIN])
            ->assertCreated()
            ->assertJsonPath('role', ['admin']);

        $this->assertFalse(User::where('personal_number', self::ADMIN)->first()->hasRole('user'));
    }
}

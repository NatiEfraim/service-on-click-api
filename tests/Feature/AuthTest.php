<?php

namespace Tests\Feature;

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
            ->assertJsonPath('data.roles.0.name', 'admin');
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

    public function test_a_person_with_no_role_can_log_in_but_is_allowed_nothing(): void
    {
        $this->postJson('/api/login', ['personal_number' => self::NO_ROLE])
            ->assertCreated()
            ->assertJsonPath('role', [])
            ->assertJsonPath('permissions', []);

        $this->actingAsPersonalNumber(self::NO_ROLE)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.roles', []);

        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::NO_ROLE)->getJson('/api/users')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAsPersonalNumber(self::NO_ROLE)->getJson('/api/bookings-approver-management/get-bookings/1')->assertForbidden();
    }
}

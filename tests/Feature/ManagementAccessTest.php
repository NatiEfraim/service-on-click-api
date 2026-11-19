<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

/** Which role reaches which management endpoint: the route middleware, as it is wired in routes/api.php. */
class ManagementAccessTest extends ManagementTestCase
{
    private const ALL_MANAGERS = ['super_admin', 'admin', 'approver', 'vice_approver'];
    private const ADMIN_AND_UP = ['super_admin', 'admin'];
    private const SUPER_ADMIN_ONLY = ['super_admin'];
    /** The bookings endpoints are the only ones a person with just the user role may call. */
    private const EVERYONE_WITH_A_ROLE = ['super_admin', 'admin', 'approver', 'vice_approver', 'user'];

    /** @return array<string, array{0: string, 1: array<int, string>}> */
    public static function endpoints(): array
    {
        return [
            'my bookings' => ['/api/bookings/my-bookings', self::EVERYONE_WITH_A_ROLE],
            'users' => ['/api/users', self::ADMIN_AND_UP],
            'users by identifier' => ['/api/users/1000', self::ADMIN_AND_UP],
            'dashboard' => ['/api/bookings-approver-management/dashboard/car-booking', self::ALL_MANAGERS],
            'filtered dashboard' => ['/api/bookings-approver-management/filtered-dashboard/car-booking', self::ALL_MANAGERS],
            'vice approvers' => ['/api/bookings-approver-management/get-vice-approvers/1', self::ALL_MANAGERS],
            'bookings' => ['/api/bookings-approver-management/get-bookings/1', self::ALL_MANAGERS],
            'system references' => ['/api/bookings-approver-management/system-references/car-booking', self::ALL_MANAGERS],
            'barber dates' => ['/api/bookings-approver-management/barber-bookings/dates', self::ALL_MANAGERS],
            'approvers' => ['/api/bookings-admin-management/get-approvers', self::ADMIN_AND_UP],
            'admins' => ['/api/bookings-super-admin-management/get-admins', self::SUPER_ADMIN_ONLY],
            'selects' => ['/api/bookings-super-admin-management/index-selects/1/car-booking', self::SUPER_ADMIN_ONLY],
            'statement' => ['/api/bookings-super-admin-management/get-statement/1', self::SUPER_ADMIN_ONLY],
        ];
    }

    /** @param array<int, string> $allowedRoles */
    #[DataProvider('endpoints')]
    public function test_each_role_reaches_only_its_own_endpoints(string $uri, array $allowedRoles): void
    {
        $people = [
            'super_admin' => self::SUPER_ADMIN,
            'admin' => self::ADMIN,
            'approver' => self::APPROVER,
            'vice_approver' => self::VICE_APPROVER,
            'user' => self::PLAIN_USER,
            'no_role' => self::NO_ROLE,
        ];

        foreach ($people as $role => $personalNumber) {
            $this->app['auth']->forgetGuards();

            $response = $this->actingAsPersonalNumber($personalNumber)->getJson($uri);

            if (in_array($role, $allowedRoles, true)) {
                $this->assertNotContains($response->status(), [401, 403, 500], "$role should reach $uri");
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_the_dashboard_counts_the_bookings_of_the_managers_unit_per_status(): void
    {
        $response = $this->actingAsPersonalNumber(self::APPROVER)
            ->getJson('/api/bookings-approver-management/dashboard/car-booking')
            ->assertOk();

        // One synthetic booking per status the type can reach (capped at six).
        $this->assertSame(6, $response->json('data.dashboard.total_bookings'));
        $response->assertJsonMissingPath('data.auth_user_permission');
    }

    public function test_the_bookings_list_comes_with_its_formatted_values(): void
    {
        $response = $this->actingAsPersonalNumber(self::VICE_APPROVER)
            ->getJson('/api/bookings-approver-management/get-bookings/13')
            ->assertOk();

        $bookings = $response->json('data');

        $this->assertCount(6, $bookings);

        foreach ($bookings as $booking) {
            $this->assertNotEmpty($booking['booking_values'], 'the values were formatted');
        }
    }

    public function test_a_booking_type_route_name_that_does_not_exist_has_nothing_to_show(): void
    {
        $this->actingAsPersonalNumber(self::APPROVER)
            ->getJson('/api/bookings-approver-management/system-references/no-such-booking')
            ->assertNoContent();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\StatusBookingEnum;
use App\Models\ApplicationLog;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingRestrictionDate;
use App\Models\BookingValue;
use App\Models\SystemReference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** What a screen opens with, the figures, the settings view, and what blocking a date does - by role. */
class ManagementInsightTest extends ManagementTestCase
{
    private const BASE = '/api/bookings-approver-management';
    private const ADMIN_BASE = '/api/bookings-admin-management';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::create($attributes + [
            'serial_number' => 800000001,
            'unit_id' => 1,
            'booking_type_id' => 1,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => 15,
            'ext_status_id' => 15,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);
    }

    /** A timeline entry at a given time (created_at is not mass-assignable). */
    private function logEntry(Booking $booking, int $actionTypeId, int $statusId, string $source, int $userId, \DateTimeInterface $at): ApplicationLog
    {
        $log = ApplicationLog::create([
            'booking_id' => $booking->id,
            'action_type_id' => $actionTypeId,
            'status_id' => $statusId,
            'source' => $source,
            'user_id' => $userId,
            'is_exported' => $source === 'external',
        ]);

        $log->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $log;
    }

    //----------------------------- me & catalog ------------------------------//

    public function test_me_tells_what_each_role_may_do(): void
    {
        $super = $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertTrue($super['is_super_admin']);
        $this->assertTrue($super['capabilities']['audit.view']);
        $this->assertTrue($super['capabilities']['config.global']);
        $this->assertCount(9, $super['managed_booking_types']);

        $admin = $this->as(self::ADMIN)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertTrue($admin['is_admin']);
        $this->assertTrue($admin['capabilities']['users.manage']);
        $this->assertTrue($admin['capabilities']['reports.view']);
        $this->assertFalse($admin['capabilities']['audit.view']);
        $this->assertFalse($admin['capabilities']['config.global']);

        $vice = $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertTrue($vice['is_handler_only']);
        $this->assertFalse($vice['capabilities']['dashboard.view']);
        $this->assertContains('vice_approver_1', $vice['permissions']);
    }

    public function test_the_catalog_has_the_lists_of_the_dropdowns_and_each_role_sees_only_its_own_unit(): void
    {
        $super = $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/catalog')->assertOk()->json('data');
        $this->assertCount(18, $super['statuses']);
        $this->assertCount(9, $super['stages']);
        $this->assertCount(4, $super['units']);
        $this->assertSame(['id' => 1, 'code' => 'H', 'name' => 'H'], $super['units'][0]);
        $this->assertNotEmpty($super['booking_type_statuses'][1]);
        $this->assertNotEmpty($super['action_types']);

        $admin = $this->as(self::ADMIN)->getJson(self::BASE . '/catalog')->assertOk()->json('data');
        $this->assertCount(1, $admin['units'], 'the unit of the admin only');
    }

    //----------------------------- Figures by role ---------------------------//

    public function test_the_main_screen_and_the_reports_are_for_the_admin_and_the_super_admin(): void
    {
        $this->booking();

        $home = $this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/home')->assertOk()->json('data');
        $this->assertCount(9, $home['domains']);
        $this->assertSame(1, $home['domains'][0]['booking_type_id']);

        $this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/reports?types=1')->assertOk()
            ->assertJsonPath('data.truncated', false);

        // The approver (domain commander) and the vice approver (handler) get neither - the route stops them or the service does.
        $this->as(self::APPROVER)->getJson(self::ADMIN_BASE . '/home')->assertForbidden();
        $this->as(self::APPROVER)->getJson(self::ADMIN_BASE . '/reports')->assertForbidden();
        $this->as(self::VICE_APPROVER)->getJson(self::ADMIN_BASE . '/reports')->assertForbidden();
    }

    public function test_the_domain_dashboard_is_for_the_roles_that_lead_a_domain(): void
    {
        $this->booking();

        // The seeded development bookings of the type count too: compare with what is stored.
        $open = Booking::where('booking_type_id', 1)->where('unit_id', 1)->whereNotIn('status_id', StatusBookingEnum::terminalIds())->count();

        $this->as(self::APPROVER)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk()
            ->assertJsonPath('data.booking_type_ids', [1])
            ->assertJsonPath('data.totals.open', $open);

        $this->as(self::ADMIN)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk();
        $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk();
        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/domain-dashboard')->assertForbidden();
    }

    public function test_the_overview_counts_what_waits_for_the_user(): void
    {
        $this->booking(['serial_number' => 800000001, 'status_id' => 15]);
        $this->booking(['serial_number' => 800000002, 'status_id' => 1]);

        $approver = $this->as(self::APPROVER)->getJson(self::BASE . '/overview')->assertOk()->json('data');

        $this->assertGreaterThanOrEqual(2, $approver['open_total']);
        $this->assertContains(800000001, $approver['awaiting_me']['serials'], 'at the approver stage');
        $this->assertNotContains(800000002, $approver['awaiting_me']['serials'], 'still with the vice approver');
        $this->assertCount(30, $approver['trend']);

        $vice = $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/overview')->assertOk()->json('data');
        $this->assertContains(800000002, $vice['awaiting_me']['serials']);
        $this->assertNotContains(800000001, $vice['awaiting_me']['serials']);
    }

    public function test_the_bottlenecks_show_where_bookings_wait_and_how_long_they_took(): void
    {
        $booking = $this->booking(['status_id' => 15, 'status_changed_at' => now()->subHours(5)]);

        // The booking waited 20 hours at the vice approver, then has been 5 hours at the approver.
        $vice = User::where('personal_number', self::VICE_APPROVER)->firstOrFail();
        $this->logEntry($booking, 1, 1, 'external', $booking->user_id, now()->subHours(25));
        $this->logEntry($booking, 3, 15, 'internal', $vice->id, now()->subHours(5));

        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 48])->assertOk();

        $data = $this->as(self::ADMIN)->getJson(self::BASE . '/bottlenecks?types=1')->assertOk()->json('data');

        $stages = collect($data['stages'])->keyBy('stage');
        $this->assertSame(1, $stages['approver']['open_count']);
        $this->assertEqualsWithDelta(20.0, $stages['vice_approver']['median_hours'], 0.2);
        $this->assertSame($vice->full_name, array_key_first($stages['vice_approver']['top_handlers']), 'the person who moved it on');
        $this->assertSame(0, $stages['approver']['breached_open'], 'well inside the 48 hours');
        $this->assertSame(1, $data['pools'][0]['open'] ?? null);
    }

    //----------------------------- Targets -----------------------------------//

    public function test_a_target_is_set_per_booking_type_by_its_admin_and_removed_with_null(): void
    {
        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 24, 'warn_ratio' => 0.7])->assertOk();

        $this->assertEquals(24, $this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/targets')->json('data.0.target_hours'));
        $this->assertDatabaseHas('audit_log', ['action' => 'config.sla_target.set', 'entity_id' => '1']);

        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => null])->assertOk();
        $this->assertSame([], $this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/targets')->json('data'));
        $this->assertDatabaseHas('audit_log', ['action' => 'config.sla_target.remove']);
    }

    public function test_a_target_is_refused_outside_the_admins_booking_types_and_out_of_range(): void
    {
        // An admin of booking type 1 only.
        $admin = User::factory()->create(['personal_number' => '1000098', 'unit_id' => 1]);
        $admin->assignRole('admin');
        $admin->givePermissionTo('admin_1');

        $this->as('1000098')->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 2, 'target_hours' => 24])->assertForbidden();
        $this->assertDatabaseHas('audit_log', ['action' => 'config.sla_target.set', 'result' => 'denied']);

        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 0.5])->assertUnprocessable();
        $this->as(self::ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 10, 'warn_ratio' => 1.5])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 2, 'target_hours' => 24])->assertOk();

        // The approver and the vice approver cannot reach the route.
        $this->as(self::APPROVER)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 24])->assertForbidden();
    }

    //----------------------------- Settings ----------------------------------//

    public function test_the_settings_of_a_booking_type_are_shown_to_whoever_manages_it_in_that_unit(): void
    {
        $this->booking();

        $data = $this->as(self::APPROVER)->getJson(self::BASE . '/settings/1/1')->assertOk()->json('data');

        $this->assertNotEmpty($data['system_references']);
        $this->assertNotEmpty($data['form_fields']);
        $this->assertSame(
            Booking::where('booking_type_id', 1)->where('unit_id', 1)->whereNotIn('status_id', StatusBookingEnum::terminalIds())->count(),
            $data['open_count']
        );
        $this->assertFalse($data['can_edit_global']);
        $this->assertNotEmpty(array_filter($data['managers'], fn (array $manager) => in_array('approver', $manager['roles'], true)));

        $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/settings/1/1')->assertOk()->assertJsonPath('data.can_edit_global', true);

        // Another unit, and a handler, are refused.
        $this->as(self::APPROVER)->getJson(self::BASE . '/settings/2/1')->assertForbidden();
        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/settings/1/1')->assertForbidden();
    }

    //----------------------------- Blocking dates ----------------------------//

    /** A working day (the existing validator refuses Friday and Saturday as blocked dates) some days from today, as Y-m-d. */
    private function workingDay(int $daysFromToday): string
    {
        $day = now()->addDays($daysFromToday);

        while ($day->isFriday() || $day->isSaturday()) {
            $day = $day->addDay();
        }

        return $day->toDateString();
    }

    /** A car booking at the approver's stage that starts on $start and ends on $end. */
    private function carBookingBetween(string $start, string $end): Booking
    {
        $booking = $this->booking();

        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 2, 'value' => $start]);
        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 4, 'value' => $end]);

        return $booking;
    }

    private function carRestrictionDatesReferenceId(): int
    {
        return (int) SystemReference::where('name', 'restriction_dates_car_booking')->where('unit_id', 1)->value('id');
    }

    public function test_the_impact_of_blocking_a_date_is_shown_before_it_is_saved(): void
    {
        $date = $this->workingDay(12);
        $this->carBookingBetween($date, now()->addDays(14)->toDateString());
        $referenceId = $this->carRestrictionDatesReferenceId();

        $impact = $this->as(self::APPROVER)
            ->postJson(self::BASE . "/settings/references/{$referenceId}/impact", ['dates' => [$date]])
            ->assertOk()
            ->json('data');

        $this->assertSame([$date], $impact['new_dates']);
        $this->assertSame([800000001], $impact['affected_serials']);
        $this->assertSame(7, $impact['cancel_status_id']);

        // Nothing was saved or canceled by looking.
        $this->assertSame(0, BookingRestrictionDate::count());
        $this->assertSame(15, (int) Booking::where('serial_number', 800000001)->value('status_id'));

        // A date that is blocked already is not new.
        BookingRestrictionDate::create(['booking_type_id' => 1, 'date' => $date]);
        $this->as(self::APPROVER)
            ->postJson(self::BASE . "/settings/references/{$referenceId}/impact", ['dates' => [$date]])
            ->assertOk()
            ->assertJsonPath('data.affected_count', 0);

        // Not a list of blocked dates.
        $other = SystemReference::where('name', 'contact_phone_car_booking')->where('unit_id', 1)->value('id');
        $this->as(self::APPROVER)->postJson(self::BASE . "/settings/references/{$other}/impact", ['dates' => []])->assertUnprocessable();
    }

    public function test_blocking_a_date_cancels_the_bookings_on_it_and_leaves_the_traces_of_a_decision(): void
    {
        $date = $this->workingDay(12);
        $booking = $this->carBookingBetween($date, now()->addDays(14)->toDateString());
        $referenceId = $this->carRestrictionDatesReferenceId();

        $this->as(self::APPROVER)
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$referenceId => ['dates' => [$date]]]])
            ->assertOk();

        $booking->refresh();
        $this->assertSame(7, (int) $booking->status_id, 'canceled by the approver');
        $this->assertSame(2, $booking->version);
        $this->assertFalse($booking->is_status_exported, 'the cancellation is sent back to the mobile app');
        $this->assertSame('internal', $booking->status_source);

        $log = ApplicationLog::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(7, (int) $log->status_id);
        $this->assertSame('system', $log->source);

        $audit = AuditLog::where('action', 'booking.canceled_by_restriction')->firstOrFail();
        $this->assertSame('automation', $audit->source);
        $this->assertSame(800000001, (int) $audit->booking_serial);
        $this->assertSame(15, $audit->before['status_id']);

        $this->assertSame(1, BookingRestrictionDate::count());
    }

    public function test_a_closed_booking_on_a_blocked_date_is_left_alone(): void
    {
        $date = $this->workingDay(12);
        $booking = $this->carBookingBetween($date, now()->addDays(14)->toDateString());
        $booking->update(['status_id' => 5]);
        $referenceId = $this->carRestrictionDatesReferenceId();

        $this->as(self::APPROVER)
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$referenceId => ['dates' => [$date]]]])
            ->assertOk();

        $this->assertSame(5, (int) Booking::where('serial_number', 800000001)->value('status_id'));
        $this->assertSame(0, DB::table('application_logs')->where('booking_id', $booking->id)->count());
    }
}

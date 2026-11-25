<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use App\Models\ApplicationLog;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingRestrictionDate;
use App\Models\BookingValue;
use App\Models\SystemReference;
use App\Models\User;
use App\Services\BookingQueryService;
use App\Support\AccessScope;
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
        // Every booking type there is: the mobile app's 9 and the web-only infosec / ICT ones.
        $this->assertCount(\App\Models\BookingType::query()->count(), $super['managed_booking_types']);
        $this->assertSame(app(\App\Domain\Workflow\Workflow::class)->routeTypeIds(), $super['route_booking_types']);

        $admin = $this->as(self::ADMIN)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertTrue($admin['is_admin']);
        $this->assertTrue($admin['capabilities']['users.manage']);
        $this->assertTrue($admin['capabilities']['reports.view']);
        $this->assertFalse($admin['capabilities']['audit.view']);
        $this->assertFalse($admin['capabilities']['config.global']);

        $vice = $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertTrue($vice['is_handler_only']);
        // A vice approver sees the dashboard of their own types (limited to their stage).
        $this->assertTrue($vice['capabilities']['dashboard.view']);
        $this->assertContains('vice_approver_1', $vice['permissions']);
    }

    public function test_the_catalog_has_the_lists_of_the_dropdowns_and_each_role_sees_only_its_own_unit(): void
    {
        $super = $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/catalog')->assertOk()->json('data');
        // 19 statuses shared with the mobile app (the external wait included) + the 5 of the clearance grant route
        // + the ICT desk's in_service_desk (151); 10 stages of the approver chains (external_party included)
        // + the clearance route's 4 + the desk's 2.
        $this->assertCount(25, $super['statuses']);
        $this->assertCount(16, $super['stages']);
        $this->assertSame([101, 102, 103, 104, 105], array_column($super['routes']['14']['steps'], 'status_id'));
        $this->assertSame(['requester', 'handler'], array_column($super['sections'], 'id'));
        $this->assertCount(4, $super['units']);
        $this->assertSame(['id' => 1, 'code' => 'H', 'name' => 'H'], $super['units'][0]);
        $this->assertNotEmpty($super['booking_type_statuses'][1]);

        // What no screen reads is not sent: the role, group and action type lists, a route's assignment rules and the two type columns.
        foreach (['roles', 'groups', 'action_types'] as $unread) {
            $this->assertArrayNotHasKey($unread, $super);
        }

        $this->assertSame(['id', 'name', 'display_name', 'icon', 'position'], array_keys($super['booking_types'][0]));
        $this->assertArrayNotHasKey('assignment', $super['routes']['14']);
        $this->assertArrayNotHasKey('handler_field_statuses', $super['routes']['14']);
        $this->assertNotEmpty($super['routes']['14']['roles'], 'the route keeps what the progress bar and the role labels read');

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

        $report = $this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/reports?types=1')->assertOk()->json('data');
        $this->assertSame(['rows', 'total'], array_keys($report));
        $this->assertNotEmpty($report['rows']);
        $this->assertArrayNotHasKey('group_id', $report['rows'][0]);
        $this->assertArrayHasKey('group_label', $report['rows'][0]);

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
        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/domain-dashboard')->assertOk();
    }

    public function test_the_camp_commander_sees_when_the_approver_and_vice_approver_last_viewed_and_acted(): void
    {
        $vice = User::where('personal_number', self::VICE_APPROVER)->first();

        // nobody has looked yet
        $this->as(self::ADMIN)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk()
            ->assertJsonPath('data.last_activity.vice_approver.last_view', null);

        // the vice approver opens the dashboard twice: one view is recorded (5 minute window)
        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk()
            ->assertJsonPath('data.last_activity', null);
        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'dashboard.view')->where('actor_id', $vice->actorId())->count());

        // an action of the vice approver on a booking of the type
        app(\App\Services\AuditService::class)->record([
            'action' => 'booking.status_change', 'entity_type' => 'booking', 'entity_id' => 800000001,
            'booking_serial' => 800000001, 'booking_type_id' => 1, 'after' => ['status_id' => 16],
        ], $vice);

        $this->as(self::ADMIN)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk()
            ->assertJsonPath('data.last_activity.vice_approver.last_view.name', $vice->full_name)
            ->assertJsonPath('data.last_activity.vice_approver.last_action.serial', 800000001)
            ->assertJsonPath('data.last_activity.vice_approver.last_action.status_id', 16);

        // the admin's own visits are not recorded, and only the camp commander gets the block
        $this->assertSame(1, AuditLog::where('action', 'dashboard.view')->count());
        $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk()
            ->assertJsonPath('data.last_activity', null);
        $this->as(self::APPROVER)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk()
            ->assertJsonPath('data.last_activity', null);
    }

    /** The overview as the list rows give it: every open booking built into a full row, then counted (what the endpoint did before it counted in the database). @return array<string, mixed> */
    private function overviewFromRows(string $personalNumber): array
    {
        $scope = new AccessScope(User::where('personal_number', $personalNumber)->firstOrFail());
        $rows = $scope->applyToBookings(Booking::query())
            ->whereNotIn('status_id', StatusBookingEnum::terminalIds())
            ->with(['user:id,uuid,full_name,personal_id,personal_number', 'bookingType:id,display_name', 'status:id,display_name', 'handler:id,uuid,full_name'])
            ->get()
            ->map(fn (Booking $booking): array => app(BookingQueryService::class)->row($booking, $scope));

        return json_decode(json_encode([
            'awaiting_me' => ['count' => $rows->filter(fn (array $row): bool => $row['actions'] !== [])->count()],
            'by_type' => $rows->countBy('booking_type_id')->all(),
            'by_type_stage' => $rows->groupBy('booking_type_id')->map(fn ($group) => $group->countBy('stage'))->all(),
        ]), true);
    }

    public function test_the_overview_counts_what_waits_for_the_user(): void
    {
        $approverBefore = $this->as(self::APPROVER)->getJson(self::BASE . '/overview')->assertOk()->json('data');
        $viceBefore = $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/overview')->assertOk()->json('data');

        $this->booking(['serial_number' => 800000001, 'status_id' => 15]);
        $this->booking(['serial_number' => 800000002, 'status_id' => 1]);

        $approver = $this->as(self::APPROVER)->getJson(self::BASE . '/overview')->assertOk()->json('data');
        $vice = $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/overview')->assertOk()->json('data');

        // Only the three figures the screens read.
        $this->assertSame(['awaiting_me', 'by_type', 'by_type_stage'], array_keys($approver));
        $this->assertSame(['count'], array_keys($approver['awaiting_me']));

        // The approver acts on the booking at their stage and, finally, on the one still with the vice approver ...
        $this->assertSame($approverBefore['awaiting_me']['count'] + 2, $approver['awaiting_me']['count']);
        $this->assertSame(($approverBefore['by_type']['1'] ?? 0) + 2, $approver['by_type']['1']);
        $this->assertSame(($approverBefore['by_type_stage']['1']['approver'] ?? 0) + 1, $approver['by_type_stage']['1']['approver']);
        $this->assertSame(($approverBefore['by_type_stage']['1']['vice_approver'] ?? 0) + 1, $approver['by_type_stage']['1']['vice_approver']);

        // ... the vice approver only on the one at their own stage.
        $this->assertSame($viceBefore['awaiting_me']['count'] + 1, $vice['awaiting_me']['count']);
    }

    public function test_the_overview_counted_in_the_database_equals_the_one_built_from_the_rows_for_every_role(): void
    {
        $this->createDomainPeople();

        // Open bookings of several types, stages and holds (a hold keeps the stage it was put on hold from), and a closed one.
        foreach ([
            [1, 1, null], [1, 15, null], [1, 2, null], [1, 8, 15], [1, 9, 1], [1, 10, 15], [1, 5, null],
            [2, 1, null], [2, 15, null], [2, 8, null], [3, 1, null], [3, 14, null],
            [14, 101, null], [14, 102, null], [14, 105, null],
        ] as $index => [$typeId, $statusId, $parked]) {
            $this->booking(['serial_number' => 800000100 + $index, 'booking_type_id' => $typeId, 'status_id' => $statusId, 'parked_status_id' => $parked]);
        }

        foreach ([self::SUPER_ADMIN, self::ADMIN, self::APPROVER, self::VICE_APPROVER, self::INFOSEC_OFFICER, self::INTERVIEW_COORDINATOR] as $personalNumber) {
            // The order of the keys of a map means nothing: only what is counted does.
            $this->assertEquals(
                $this->overviewFromRows($personalNumber),
                $this->as($personalNumber)->getJson(self::BASE . '/overview')->assertOk()->json('data'),
                "overview of {$personalNumber}",
            );
        }

        // No management role: the route stops the request.
        $this->as(self::NO_ROLE)->getJson(self::BASE . '/overview')->assertForbidden();
    }

    public function test_a_control_officer_gets_their_own_types_on_the_main_screen(): void
    {
        // A person whose ONLY role is רמ"ד תו"ב, for each of the two control-officer types (out-unit food since the flow switch).
        $this->createPerson('1000033', RoleEnum::TRANSPORTATION_CONTROL_OFFICER);
        $this->createPerson('1000034', RoleEnum::OUT_UNIT_FOOD_CONTROL_OFFICER);

        foreach (['1000033' => 8, '1000034' => 10] as $personalNumber => $bookingTypeId) {
            $me = $this->as((string) $personalNumber)->getJson(self::BASE . '/me')->assertOk()->json('data');

            // The main screen is built from these two keys: the officer gets it, with their own type, like a vice approver.
            $this->assertTrue($me['capabilities']['dashboard.view'], "dashboard.view of {$personalNumber}");
            $this->assertSame([$bookingTypeId], $me['dashboard_booking_types'], "dashboard types of {$personalNumber}");
            $this->assertTrue($me['is_handler_only'], 'still a handler: the queue stays scoped to their stage');

            $this->as((string) $personalNumber)->getJson(self::BASE . '/overview')->assertOk();

            // The dashboard does not open the settings of the type: a handler stays out of them.
            $this->as((string) $personalNumber)->getJson(self::BASE . "/settings/1/{$bookingTypeId}")->assertForbidden();
        }
    }

    public function test_an_admin_who_is_also_a_control_officer_gets_both_hats_on_the_main_screen(): void
    {
        // Camp commander of type 1 ONLY, and the transportation רמ"ד תו"ב - like the real מפקד מחנה the frontend reported.
        $both = User::factory()->create(['personal_number' => '1000035', 'unit_id' => 1, 'full_name' => 'מפקד מחנה ורמ"ד']);
        $both->assignRole('admin');
        $both->givePermissionTo('admin_1');
        $both->assignRole('transportation_control_officer');
        $both->givePermissionTo('transportation_control_officer_8');

        $before = $this->as('1000035')->getJson(self::BASE . '/overview')->assertOk()->json('data.by_type.8') ?? 0;

        // One transport booking at the officer's stage and one past it, at the approver's.
        $this->booking(['serial_number' => 800000201, 'booking_type_id' => 8, 'status_id' => 1]);
        $this->booking(['serial_number' => 800000202, 'booking_type_id' => 8, 'status_id' => 15]);

        $me = $this->as('1000035')->getJson(self::BASE . '/me')->assertOk()->json('data');

        // The hats MERGE: the administered type AND the officer's type, not one or the other.
        $this->assertTrue($me['is_admin']);
        $this->assertSame([1, 8], $me['dashboard_booking_types']);

        // On type 8 they are only its handler: the overview counts the new booking at the officer's stage, not the one beyond it.
        $overview = $this->as('1000035')->getJson(self::BASE . '/overview')->assertOk()->json('data');
        $this->assertSame($before + 1, $overview['by_type'][8] ?? 0);

        // Admin of type 1: its settings open; only the רמ"ד of type 8: its settings stay closed.
        $this->as('1000035')->getJson(self::BASE . '/settings/1/1')->assertOk();
        $this->as('1000035')->getJson(self::BASE . '/settings/1/8')->assertForbidden();
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

        // Another unit, and a handler, are refused. The admin too is bound to their own unit, and to the types of their admin_N permissions.
        $this->as(self::APPROVER)->getJson(self::BASE . '/settings/2/1')->assertForbidden();
        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/settings/1/1')->assertForbidden();
        $this->as(self::ADMIN)->getJson(self::BASE . '/settings/1/1')->assertOk();
        $this->as(self::ADMIN)->getJson(self::BASE . '/settings/2/1')->assertForbidden();

        // The infosec officer commands the ביטחון מידע system: the settings of their officer types open (view), own unit only.
        $this->createDomainPeople();
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/settings/1/15')->assertOk();
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/settings/2/15')->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/settings/1/1')->assertForbidden();
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

        // Only the two figures the screen shows: the new dates and how many bookings they would cancel.
        $this->assertSame(['new_dates', 'affected_count'], array_keys($impact));
        $this->assertSame([$date], $impact['new_dates']);
        $this->assertSame(1, $impact['affected_count']);

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

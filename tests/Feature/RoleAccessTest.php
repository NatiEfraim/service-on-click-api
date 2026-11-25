<?php

namespace Tests\Feature;

use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use App\Models\UnitHasBookingType;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * What each kind of person can open and what they can handle (frontend's "access gaps per user type" report): the infosec officer and the ICT
 * handler handle the whole route of their own types and open requests of them, a person made on the website can open requests, and the roles
 * screen grants and takes away the requester permission. One person per role: the five of ManagementTestCase, the three of the clearance
 * grant, and here the ICT handler and the transportation control officer.
 */
class RoleAccessTest extends ManagementTestCase
{
    private const BASE = '/api/bookings-approver-management';
    private const ADMIN_BASE = '/api/bookings-admin-management';

    private const ICT_HANDLER = '1000023';
    private const CONTROL_OFFICER = '1000024';

    private int $serial = 800001000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDomainPeople();
        $this->createPerson(self::ICT_HANDLER, RoleEnum::ICT_HANDLER);
        $this->createPerson(self::CONTROL_OFFICER, RoleEnum::TRANSPORTATION_CONTROL_OFFICER);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** @return array<int, int> */
    private function infosecTypeIds(): array
    {
        return [BookingTypeEnum::REMOVABLE_MEDIA_BOOKING->getBookingTypeId(), BookingTypeEnum::SECURITY_INCIDENT_BOOKING->getBookingTypeId()];
    }

    /** @return array<int, int> */
    private function ictTypeIds(): array
    {
        return array_map(fn (BookingTypeEnum $type): int => $type->getBookingTypeId(), [
            BookingTypeEnum::SOFTWARE_INSTALL_BOOKING,
            BookingTypeEnum::PRINTER_INSTALL_BOOKING,
            BookingTypeEnum::EMAIL_FAULT_BOOKING,
            BookingTypeEnum::USER_RENAME_BOOKING,
            BookingTypeEnum::USER_OPEN_BOOKING,
            BookingTypeEnum::USER_MOVE_BOOKING,
        ]);
    }

    private function clearanceTypeId(): int
    {
        return BookingTypeEnum::CLEARANCE_GRANT_BOOKING->getBookingTypeId();
    }

    /** The ordinary types the mobile app has (everything below the clearance grant; their ids are not contiguous). @return array<int, int> */
    private function plainTypeIds(): array
    {
        return BookingType::query()->where('id', '<', $this->clearanceTypeId())->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** How many open requests of the type the people of unit 1 have: the seeded development bookings count too. */
    private function openOf(int $typeId): int
    {
        return Booking::where('booking_type_id', $typeId)->where('unit_id', 1)->whereNotIn('status_id', StatusBookingEnum::terminalIds())->count();
    }

    private function booking(int $typeId, int $statusId = 1, array $attributes = []): Booking
    {
        return Booking::create($attributes + [
            'serial_number' => ++$this->serial,
            'unit_id' => 1,
            'booking_type_id' => $typeId,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => $statusId,
            'status_source' => 'internal',
            'status_changed_at' => now(),
            'version' => 1,
        ]);
    }

    /** @return array<int, int> every type of the person's requester catalog, sorted */
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

    /** @return array<int, int> the types the unit of the people offers, sorted */
    private function unitTypeIds(): array
    {
        return UnitHasBookingType::where('unit_id', 1)->pluck('booking_type_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }

    /** What the screens learn about a person from `me`. @return array<string, mixed> */
    private function me(string $personalNumber): array
    {
        $me = $this->as($personalNumber)->getJson(self::BASE . '/me')->assertOk()->json('data');

        foreach (['managed_booking_types', 'dashboard_booking_types', 'route_booking_types'] as $key) {
            sort($me[$key]);
        }

        return $me;
    }

    //----------------------------- The officer's route (gaps 1 and 2) --------//

    public function test_the_infosec_officer_answers_and_closes_the_requests_of_their_types(): void
    {
        [$typeId] = $this->infosecTypeIds();
        $booking = $this->booking($typeId);

        // The queue is no longer empty: the request waits for them, with the one answer of its route.
        $row = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings?mine=1&ids=' . $booking->serial_number)->assertOk()->json('data.0');
        $this->assertSame('vice_approver', $row['stage'], 'the generic stage "waiting for the handling party"');
        $this->assertSame([3, 4], array_column($row['actions'], 'status_id'));
        $this->assertSame([false, true], array_column($row['actions'], 'comment_required'));

        $url = self::BASE . '/bookings/' . $booking->uuid . '/status';

        // The rejection needs a comment, as everywhere.
        $this->as(self::INFOSEC_OFFICER)->postJson($url, ['status_id' => 4, 'version' => 1])->assertUnprocessable();
        // Nobody passes it on to a stage nobody holds.
        $this->as(self::INFOSEC_OFFICER)->postJson($url, ['status_id' => 15, 'version' => 1])->assertForbidden();

        $this->as(self::INFOSEC_OFFICER)->postJson($url, ['status_id' => 3, 'version' => 1])->assertOk();

        // Approved: the realization stage, where the officer closes it.
        $row = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings?ids=' . $booking->serial_number)->assertOk()->json('data.0');
        $this->assertSame('realization', $row['stage']);
        $this->assertSame([5, 7], array_column($row['actions'], 'status_id'));

        $this->as(self::INFOSEC_OFFICER)->postJson($url, ['status_id' => 5, 'version' => 2])->assertOk();

        // A closed request stays in their list (they are not a handler who sees only the stage they act on).
        $row = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings?ids=' . $booking->serial_number)->assertOk()->json('data.0');
        $this->assertSame('closed', $row['stage']);
        $this->assertSame([], $row['actions']);
        $this->assertSame(5, $row['status_id']);
    }

    public function test_the_infosec_officer_rejects_with_a_comment_and_takes_over_a_request_left_at_the_old_stages(): void
    {
        [, $secondTypeId] = $this->infosecTypeIds();
        $rejected = $this->booking($secondTypeId);
        $legacy = $this->booking($secondTypeId, 15);

        $this->as(self::INFOSEC_OFFICER)
            ->postJson(self::BASE . '/bookings/' . $rejected->uuid . '/status', ['status_id' => 4, 'version' => 1, 'comment' => 'הבקשה אינה נדרשת'])
            ->assertOk();
        $this->assertSame(4, (int) $rejected->fresh()->status_id);

        // A request left at the old "passed on to the approver" status waits for the officer too: nothing is stranded.
        $row = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings?ids=' . $legacy->serial_number)->assertOk()->json('data.0');
        $this->assertSame('approver', $row['stage']);
        $this->assertSame([3, 4], array_column($row['actions'], 'status_id'));
    }

    public function test_the_legacy_ict_handler_manages_nothing_and_the_desk_representative_works_the_queue(): void
    {
        $typeId = $this->ictTypeIds()[0];
        $booking = $this->booking($typeId);
        $url = self::BASE . '/bookings/' . $booking->uuid . '/status';

        // ICT runs on the service desk route now: the old single-handler role is linked to no type and sees nothing.
        $this->as(self::ICT_HANDLER)->getJson(self::BASE . '/bookings/' . $booking->uuid)->assertNotFound();
        $this->assertNotContains($booking->serial_number, collect($this->as(self::ICT_HANDLER)->getJson(self::BASE . '/bookings?per_page=100')->assertOk()->json('data'))->pluck('serial_number')->all());

        // The desk representative (the vice approver of the type) takes the request and closes it (the full flow: IctServiceDeskWorkflowTest).
        $row = $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/bookings?ids=' . $booking->serial_number)->assertOk()->json('data.0');
        $this->assertSame([151, 5, 7], array_column($row['actions'], 'status_id'));

        $this->as(self::VICE_APPROVER)->postJson($url, ['status_id' => 151, 'version' => 1])->assertOk();
        $this->as(self::VICE_APPROVER)->postJson($url, ['status_id' => 7, 'version' => 2])->assertUnprocessable('closing without doing the work needs a comment');
        $this->as(self::VICE_APPROVER)->postJson($url, ['status_id' => 7, 'version' => 2, 'comment' => 'בוטל לבקשת המזמין'])->assertOk();
        $this->assertSame(7, (int) $booking->fresh()->status_id);
    }

    public function test_an_officer_handles_only_their_own_domain_and_their_own_unit(): void
    {
        $infosec = $this->booking($this->infosecTypeIds()[0]);
        $ict = $this->booking($this->ictTypeIds()[0]);
        $otherUnit = $this->booking($this->infosecTypeIds()[0], 1, ['unit_id' => 2]);

        // Each sees the requests of their domain, in their unit, and no other: the other's request is not there to open.
        $infosecList = collect($this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings?per_page=100')->assertOk()->json('data'))->pluck('serial_number');
        $deskList = collect($this->as(self::VICE_APPROVER)->getJson(self::BASE . '/bookings?per_page=100')->assertOk()->json('data'))->pluck('serial_number');

        $this->assertTrue($infosecList->contains($infosec->serial_number));
        $this->assertFalse($infosecList->contains($ict->serial_number));
        $this->assertFalse($infosecList->contains($otherUnit->serial_number), 'the other unit\'s request');
        $this->assertTrue($deskList->contains($ict->serial_number), 'the desk representative holds the ICT queue');
        $this->assertFalse($deskList->contains($infosec->serial_number), 'an infosec type has no vice approver');

        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/bookings/' . $infosec->uuid)->assertNotFound();
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings/' . $ict->uuid)->assertNotFound();
        $this->as(self::VICE_APPROVER)->postJson(self::BASE . '/bookings/' . $infosec->uuid . '/status', ['status_id' => 3, 'version' => 1])->assertNotFound();
    }

    public function test_the_super_admin_and_the_barber_and_the_other_roles_keep_the_buttons_they_had(): void
    {
        $infosec = $this->booking($this->infosecTypeIds()[0]);

        // The super admin still holds every turn, the chain's passing-on moves included.
        $row = $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/bookings?ids=' . $infosec->serial_number)->assertOk()->json('data.0');
        $this->assertSame([15, 16, 3, 4], array_column($row['actions'], 'status_id'));

        // The plain chain of a car booking is untouched: the vice approver passes it on, the approver decides.
        $car = $this->booking(1);
        $viceRow = $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/bookings?ids=' . $car->serial_number)->assertOk()->json('data.0');
        $this->assertSame([15, 16], array_column($viceRow['actions'], 'status_id'));
        $approverRow = $this->as(self::APPROVER)->getJson(self::BASE . '/bookings?ids=' . $car->serial_number)->assertOk()->json('data.0');
        $this->assertSame([3, 4], array_column($approverRow['actions'], 'status_id'));

        // An approver of the cars has nothing on an infosec request.
        $this->as(self::APPROVER)->getJson(self::BASE . '/bookings/' . $infosec->uuid)->assertNotFound();
    }

    public function test_the_officer_pool_counts_the_holders_of_the_officer_role(): void
    {
        $this->booking($this->infosecTypeIds()[0]);

        $pools = $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/bottlenecks?types=' . $this->infosecTypeIds()[0])->assertOk()->json('data.pools');

        $this->assertNotEmpty($pools);
        $this->assertSame(RoleEnum::INFOSEC_OFFICER->getPermissionDisplayName(), $pools[0]['role']);
        $this->assertGreaterThanOrEqual(1, $pools[0]['holders']);
    }

    public function test_the_officers_have_a_dashboard_a_queue_and_the_settings_view_for_their_types(): void
    {
        $infosecOpen = $this->openOf($this->infosecTypeIds()[0]);

        $this->booking($this->infosecTypeIds()[0]);
        $this->booking($this->ictTypeIds()[0]);

        $infosec = $this->me(self::INFOSEC_OFFICER);
        $this->assertSame(array_merge([$this->clearanceTypeId()], $this->infosecTypeIds()), $infosec['managed_booking_types']);
        $this->assertSame($infosec['managed_booking_types'], $infosec['dashboard_booking_types']);
        $this->assertTrue($infosec['capabilities']['dashboard.view']);
        $this->assertFalse($infosec['is_handler_only']);

        // The legacy ict_handler role is linked to no type: ICT runs on the service desk route (IctServiceDeskWorkflowTest).
        $ict = $this->me(self::ICT_HANDLER);
        $this->assertSame([], $ict['managed_booking_types']);
        $this->assertSame([], $ict['dashboard_booking_types']);
        $this->assertFalse($ict['capabilities']['dashboard.view']);
        $this->assertSame([], $ict['route_booking_types']);

        // The desk representative works the ICT queue but does not own the route; the dashboard of the desk is the commander's.
        $representative = $this->me(self::VICE_APPROVER);
        $this->assertSame($this->ictTypeIds(), array_values(array_intersect($representative['route_booking_types'], $this->ictTypeIds())));
        $this->assertSame([], $representative['route_owner_booking_types']);

        // The dashboard of their own types; not another domain's.
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/domain-dashboard?types=' . $this->infosecTypeIds()[0])->assertOk()
            ->assertJsonPath('data.totals.open', $infosecOpen + 1);
        $this->as(self::ICT_HANDLER)->getJson(self::BASE . '/domain-dashboard?types=' . $this->ictTypeIds()[0])->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/domain-dashboard?types=' . $this->ictTypeIds()[0])->assertForbidden();

        // Settings follow the commander tier: the infosec officer commands ביטחון מידע, so their officer types open for
        // VIEWING (2026-11-25, the frontend's request) - own unit only; the write endpoints stay admin-tier (middleware).
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/settings/1/' . $this->infosecTypeIds()[0])->assertOk();
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/settings/2/' . $this->infosecTypeIds()[0])->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->postJson('/api/bookings-super-admin-management/form-fields/1/' . $this->infosecTypeIds()[0], [])->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->putJson('/api/bookings-admin-management/targets', ['booking_type_id' => $this->infosecTypeIds()[0], 'target_hours' => 24])->assertForbidden();
        // A desk representative is a handler, not a commander: the ICT settings stay closed to them.
        $this->as(self::VICE_APPROVER)->getJson(self::BASE . '/settings/1/' . $this->ictTypeIds()[0])->assertForbidden();
    }

    //----------------------------- What each role opens (gap 3) --------------//

    public function test_every_role_opens_exactly_the_types_it_may(): void
    {
        $unit = $this->unitTypeIds();
        $topics = array_merge($this->infosecTypeIds(), $this->ictTypeIds());
        $only = fn (array $ids): array => array_values(array_intersect($unit, $ids));

        $clearance = [$this->clearanceTypeId()];

        $expected = [
            // A plain user: every type with a plain requester - not the clearance grant, which only the קב"ט (and the infosec officer) open.
            self::PLAIN_USER => $only(array_merge($this->plainTypeIds(), $topics)),
            // A person with a special role opens what every employee opens of the topic types (15-25), whatever else they hold ...
            self::ICT_HANDLER => $only($topics),
            self::INTERVIEW_COORDINATOR => $only($topics),
            self::ADMIN => $only($topics),
            self::APPROVER => $only($topics),
            self::VICE_APPROVER => $only($topics),
            self::CONTROL_OFFICER => $only($topics),
            // ... plus the clearance grant for the two who own it: the infosec officer (the במ of the route) and its requester, the קב"ט.
            self::INFOSEC_OFFICER => $only(array_merge($clearance, $topics)),
            self::SECURITY_OFFICER => $only(array_merge($clearance, $topics)),
            // The super admin opens everything topic, the clearance grant included.
            self::SUPER_ADMIN => $only(array_merge($clearance, $topics)),
        ];

        foreach ($expected as $personalNumber => $types) {
            sort($types);

            $this->assertSame($types, $this->catalogTypeIds((string) $personalNumber), "the catalog of {$personalNumber}");
        }

        // A person with no role has no portal at all: the first login gives them the user role (below).
        $this->as(self::NO_ROLE)->getJson('/api/requester/catalog')->assertForbidden();
    }

    public function test_everybody_with_a_role_opens_the_topic_forms_and_only_the_owners_open_the_clearance_grant(): void
    {
        $clearance = $this->clearanceTypeId();

        // The topic types (15-25): the form of every person with a role, whichever domain they handle.
        foreach ([self::INFOSEC_OFFICER, self::ICT_HANDLER, self::INTERVIEW_COORDINATOR, self::SECURITY_OFFICER, self::APPROVER] as $person) {
            $this->as($person)->getJson('/api/requester/form/' . $this->infosecTypeIds()[0])->assertOk();
            $this->as($person)->getJson('/api/requester/form/' . $this->ictTypeIds()[0])->assertOk();
        }

        // The clearance grant: its requester (the קב"ט) and its owner (the infosec officer, the במ), nobody else - the coordinator, the camp
        // commander and the other management roles do not.
        $this->as(self::INFOSEC_OFFICER)->getJson('/api/requester/form/' . $clearance)->assertOk();
        $this->as(self::SECURITY_OFFICER)->getJson('/api/requester/form/' . $clearance)->assertOk();

        foreach ([self::INTERVIEW_COORDINATOR, self::ICT_HANDLER, self::ADMIN, self::APPROVER, self::VICE_APPROVER, self::PLAIN_USER] as $person) {
            $this->as($person)->getJson('/api/requester/form/' . $clearance)->assertForbidden();
        }

        // Submitting follows the same rule: an empty form is refused for what it lacks (422), not for who sends it (403).
        foreach ([self::INFOSEC_OFFICER, self::ICT_HANDLER, self::INTERVIEW_COORDINATOR] as $person) {
            $this->as($person)->postJson('/api/bookings/' . $this->infosecTypeIds()[0], [])->assertUnprocessable();
            $this->as($person)->postJson('/api/bookings/' . $this->ictTypeIds()[0], [])->assertUnprocessable();
        }

        $this->as(self::INFOSEC_OFFICER)->postJson('/api/bookings/' . $clearance, [])->assertUnprocessable();
        $this->as(self::INTERVIEW_COORDINATOR)->postJson('/api/bookings/' . $clearance, [])->assertForbidden();
        $this->as(self::ICT_HANDLER)->postJson('/api/bookings/' . $clearance, [])->assertForbidden();

        // The ordinary types stay with user_{id}: a special role does not open the cars.
        $this->as(self::INFOSEC_OFFICER)->postJson('/api/bookings/1', [])->assertForbidden();
        $this->as(self::ICT_HANDLER)->getJson('/api/requester/form/1')->assertForbidden();
    }

    public function test_the_infosec_officer_uses_the_person_lookup_of_the_clearance_grant_like_its_requester(): void
    {
        $clearance = $this->clearanceTypeId();
        $lookup = fn (string $person) => $this->as($person)->getJson('/api/requester/person-lookup?booking_type_id=' . $clearance . '&personal_id=12345');

        // Authorized (the ID number is too short, so the answer is the validation's 422, not the 403 of who asks).
        $lookup(self::INFOSEC_OFFICER)->assertUnprocessable();
        $lookup(self::SECURITY_OFFICER)->assertUnprocessable();

        foreach ([self::INTERVIEW_COORDINATOR, self::ICT_HANDLER, self::PLAIN_USER, self::ADMIN] as $person) {
            $lookup($person)->assertForbidden();
        }
    }

    //----------------------------- What every role sees of the management side --//

    public function test_me_tells_every_management_role_what_it_manages_and_whose_dashboard_it_has(): void
    {
        $plain = $this->plainTypeIds();
        $barber = BookingTypeEnum::BARBER_BOOKING->getBookingTypeId();
        $transport = BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId();
        $withoutBarber = array_values(array_diff($plain, [$barber]));

        $admin = $this->me(self::ADMIN);
        $this->assertSame($plain, $admin['managed_booking_types']);
        $this->assertSame($plain, $admin['dashboard_booking_types']);
        $this->assertFalse($admin['is_handler_only']);

        $approver = $this->me(self::APPROVER);
        $this->assertSame($plain, $approver['managed_booking_types']);
        $this->assertSame($plain, $approver['dashboard_booking_types']);
        $this->assertFalse($approver['is_handler_only']);

        // The vice approver handles one stage of every type that has the role (the barber has none) - the ICT types too, where
        // they are the desk's representative - a queue, no dashboard of their own commander.
        $vice = $this->me(self::VICE_APPROVER);
        $this->assertSame(array_values(array_merge($withoutBarber, $this->ictTypeIds())), $vice['managed_booking_types']);
        $this->assertTrue($vice['is_handler_only']);

        // The control officer handles the transportation type only - and since 2026-11-25 (the frontend's main-screen
        // request) gets its dashboard tile too, stage-limited like a vice approver.
        $officer = $this->me(self::CONTROL_OFFICER);
        $this->assertSame([$transport], $officer['managed_booking_types']);
        $this->assertSame([$transport], $officer['dashboard_booking_types']);
        $this->assertTrue($officer['is_handler_only']);

        // The clearance route: the במ owns it, the coordinator works it, the קב"ט only submits.
        $coordinator = $this->me(self::INTERVIEW_COORDINATOR);
        $this->assertSame([$this->clearanceTypeId()], $coordinator['managed_booking_types']);
        $this->assertSame([], $coordinator['dashboard_booking_types']);
        $this->assertTrue($coordinator['is_handler_only']);
        $this->as(self::SECURITY_OFFICER)->getJson(self::BASE . '/me')->assertForbidden();

        // The super admin manages everything.
        $super = $this->me(self::SUPER_ADMIN);
        $this->assertTrue($super['is_super_admin']);
        $this->assertSame(\App\Models\BookingType::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(), $super['managed_booking_types']);

        // Plain users and people with no role have no management side at all.
        $this->as(self::PLAIN_USER)->getJson(self::BASE . '/me')->assertForbidden();
        $this->as(self::NO_ROLE)->getJson(self::BASE . '/me')->assertForbidden();
    }

    //----------------------------- A person made on the website (gap 4) ------//

    public function test_a_user_made_on_the_website_can_open_requests(): void
    {
        $this->as(self::ADMIN)->postJson('/api/users', ['personal_number' => '3000010', 'full_name' => 'משתמש חדש', 'phone' => '0501234567', 'unit_id' => 1])
            ->assertCreated();

        $user = User::where('personal_number', '3000010')->firstOrFail();
        $requesterTypes = BookingTypeHasRole::where('role_id', RoleEnum::USER->value)->whereIn('booking_type_id', $this->unitTypeIds())->pluck('booking_type_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $expected = array_map(fn (int $id): string => 'user_' . $id, $requesterTypes);

        $this->assertTrue($user->hasRole('user'));
        $this->assertEqualsCanonicalizing($expected, $user->getDirectPermissions()->pluck('name')->all());
        $this->assertNotContains('user_' . $this->clearanceTypeId(), $user->getDirectPermissions()->pluck('name')->all(), 'the clearance grant is the קב"ט\'s');

        // And the portal is not empty: the same catalog as any plain user.
        $this->assertSame($requesterTypes, $this->catalogTypeIds('3000010'));
        $this->assertSame($this->catalogTypeIds(self::PLAIN_USER), $this->catalogTypeIds('3000010'));

        // Audited with what was granted; the users file for the mobile app carries the person again.
        $audit = AuditLog::where('action', 'user.create')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing($expected, $audit->after['permissions']);
        $this->assertFalse((bool) $user->is_exported_role);
    }

    public function test_a_person_with_no_role_gets_the_user_role_and_its_requests_at_the_first_login_and_a_manager_does_not(): void
    {
        $this->as(self::NO_ROLE)->getJson('/api/requester/catalog')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['personal_number' => self::NO_ROLE])->assertCreated();

        $person = User::where('personal_number', self::NO_ROLE)->firstOrFail();
        $this->assertTrue($person->hasRole('user'));
        $this->assertContains('user_1', $person->getDirectPermissions()->pluck('name')->all());
        $this->assertSame($this->catalogTypeIds(self::PLAIN_USER), $this->catalogTypeIds(self::NO_ROLE));

        // Somebody who already has a role is not given the plain user's permissions again at every login.
        $before = User::where('personal_number', self::APPROVER)->firstOrFail()->getDirectPermissions()->pluck('name')->sort()->values()->all();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['personal_number' => self::APPROVER])->assertCreated();
        $this->assertSame($before, User::where('personal_number', self::APPROVER)->firstOrFail()->getDirectPermissions()->pluck('name')->sort()->values()->all());
    }

    //----------------------------- The requester flag of the roles screen (gap 5) --//

    private function assignRequester(string $actor, User $person, array $assignments): \Illuminate\Testing\TestResponse
    {
        return $this->as($actor)->postJson(self::ADMIN_BASE . '/assign-approver-role', [
            'uuid' => $person->uuid,
            'reason' => 'בקשת מפקד',
            'role_assignments' => $assignments,
        ]);
    }

    public function test_the_roles_screen_shows_and_changes_the_requester_permission(): void
    {
        $person = User::where('personal_number', self::PLAIN_USER)->firstOrFail();

        $rows = collect($this->as(self::ADMIN)->getJson(self::ADMIN_BASE . '/get-approver-permissions/' . $person->uuid)->assertOk()->json('data.permissions'))->keyBy('booking_type_id');
        $this->assertTrue($rows[1]['is_user']);
        $this->assertFalse($rows[1]['is_approver']);

        // The admin sends the row as the screen draws it, with the requester flag taken off one type.
        $this->assignRequester(self::ADMIN, $person, [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => false, 'is_user' => false]])->assertOk();

        $names = $person->fresh()->getDirectPermissions()->pluck('name')->all();
        $this->assertNotContains('user_1', $names);
        $this->assertContains('user_2', $names, 'only that type');
        $this->assertTrue($person->fresh()->hasRole('user'), 'everybody stays a plain user');
        $this->assertNotContains(1, $this->catalogTypeIds(self::PLAIN_USER), 'cars are no longer offered to them');

        $audit = AuditLog::where('action', 'user.permissions.change')->latest('id')->firstOrFail();
        $this->assertContains('user_1', $audit->before['permissions']);
        $this->assertNotContains('user_1', $audit->after['permissions']);

        // A screen that does not draw the flag (it sends none) changes nothing of it.
        $this->assignRequester(self::ADMIN, $person, [['booking_type_id' => 2, 'is_approver' => false, 'is_vice_approver' => false]])->assertOk();
        $this->assertContains('user_2', $person->fresh()->getDirectPermissions()->pluck('name')->all());

        // And back.
        $this->assignRequester(self::ADMIN, $person, [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => false, 'is_user' => true]])->assertOk();
        $this->assertContains('user_1', $person->fresh()->getDirectPermissions()->pluck('name')->all());
    }

    public function test_the_requester_flag_follows_the_same_limits_as_every_other_flag(): void
    {
        $person = User::where('personal_number', self::PLAIN_USER)->firstOrFail();
        [$infosecTypeId] = $this->infosecTypeIds();

        // An admin grants only on the types they administer: an infosec type is the super admin's.
        $this->assignRequester(self::ADMIN, $person, [['booking_type_id' => $infosecTypeId, 'is_infosec_officer' => false, 'is_user' => false]])->assertForbidden();
        $this->assertContains('user_' . $infosecTypeId, $person->fresh()->getDirectPermissions()->pluck('name')->all());

        // The super admin may; the row of the type carries the flag the same way.
        $this->assignRequester(self::SUPER_ADMIN, $person, [['booking_type_id' => $infosecTypeId, 'is_infosec_officer' => false, 'is_user' => false]])->assertOk();
        $this->assertNotContains('user_' . $infosecTypeId, $person->fresh()->getDirectPermissions()->pluck('name')->all());

        // A type whose requester is not the plain user (the clearance grant) has no such flag: it cannot be granted (422); taking it away is ignored.
        $rows = collect($this->as(self::SUPER_ADMIN)->getJson(self::ADMIN_BASE . '/get-approver-permissions/' . $person->uuid)->assertOk()->json('data.permissions'))->keyBy('booking_type_id');
        $this->assertArrayNotHasKey('is_user', $rows[$this->clearanceTypeId()]);
        $this->assertArrayHasKey('is_user', $rows[$infosecTypeId]);

        $clearanceRow = ['booking_type_id' => $this->clearanceTypeId(), 'is_infosec_officer' => false, 'is_department_security_officer' => false, 'is_interview_coordinator' => false];
        $this->assignRequester(self::SUPER_ADMIN, $person, [[...$clearanceRow, 'is_user' => true]])->assertUnprocessable();
        $this->assignRequester(self::SUPER_ADMIN, $person, [[...$clearanceRow, 'is_user' => false]])->assertOk();
        $this->assertNotContains('user_' . $this->clearanceTypeId(), $person->fresh()->getDirectPermissions()->pluck('name')->all());

        // Not a boolean: refused like any other flag.
        $this->assignRequester(self::SUPER_ADMIN, $person, [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => false, 'is_user' => 'maybe']])->assertUnprocessable();
    }
}

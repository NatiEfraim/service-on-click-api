<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * The ICT service desk route (types 20-25): the representative (נציג מוקד, vice_approver) takes, closes and puts back,
 * the commander (מפקד תקשוב, admin_ict) reopens, appoints the desk's people and is appointed only by the super admin;
 * the printer IP depends on the install type; the user-open lookup locks what it found - but not the phone.
 */
class IctServiceDeskWorkflowTest extends ManagementTestCase
{
    private const SOFTWARE_INSTALL = 20;
    private const PRINTER_INSTALL = 21;
    private const USER_OPEN = 24;
    private const BASE = '/api/bookings-approver-management';

    /** The desk commander, made per test run; the representative is the seeded vice approver (vice_approver_20..25 through the route). */
    private const COMMANDER = '1000030';
    private const REPRESENTATIVE = self::VICE_APPROVER;

    protected function setUp(): void
    {
        parent::setUp();

        if (!User::where('personal_number', self::COMMANDER)->exists()) {
            $this->createPerson(self::COMMANDER, RoleEnum::ADMIN_ICT);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Send the next request as another person (the test client keeps the guard of the one before). */
    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    //----------------------------- Fixtures ------------------------------------//

    /** A request of the plain user waiting in the desk's queue (status 1). */
    private function request(int $bookingTypeId = self::SOFTWARE_INSTALL): Booking
    {
        static $serial = 800000500;

        return Booking::create([
            'serial_number' => ++$serial,
            'unit_id' => UnitEnum::H->value,
            'booking_type_id' => $bookingTypeId,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => 1,
            'status_source' => 'web',
            'status_changed_at' => now(),
            'version' => 1,
            'is_status_exported' => true,
        ]);
    }

    private function url(Booking $booking, string $suffix = ''): string
    {
        return self::BASE . '/bookings/' . $booking->uuid . $suffix;
    }

    private function user(string $personalNumber): User
    {
        return User::where('personal_number', $personalNumber)->firstOrFail();
    }

    private function field(int $bookingTypeId, string $name): FormField
    {
        return FormField::query()->where('booking_type_id', $bookingTypeId)->where('unit_id', UnitEnum::H->value)->where('field_name', $name)->firstOrFail();
    }

    private function optionId(FormField $field, string $name): int
    {
        return (int) OptionField::query()->where('form_field_id', $field->id)->where('name', $name)->value('id');
    }

    /** The stored scalar of one field of the booking (null when no row holds one). */
    private function valueOf(Booking $booking, FormField $field): ?string
    {
        $row = BookingValue::query()->where('booking_id', $booking->id)->where('form_field_id', $field->id)->first();
        $value = $row?->value;

        if (is_array($value) && array_is_list($value) && count($value) === 1) {
            $value = $value[0];
        }

        return $value === null ? null : (string) $value;
    }

    //----------------------------- The route -----------------------------------//

    public function test_the_representative_takes_a_request_closes_it_and_puts_it_back(): void
    {
        $booking = $this->request();

        // The queue: the take and the two closes, all enabled (no handler fields on the ICT forms).
        $detail = $this->as(self::REPRESENTATIVE)->getJson($this->url($booking))->assertOk()->json('data');
        $this->assertSame('service_desk_queue', $detail['stage']);
        $this->assertSame([151, 5, 7], array_column($detail['actions'], 'status_id'));
        $this->assertSame('לקיחה לטיפול', $detail['actions'][0]['label']);

        // Taking it assigns the presser as the handler in the same move.
        $this->as(self::REPRESENTATIVE)->postJson($this->url($booking, '/status'), ['status_id' => 151, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status_id', 151)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.sync_state', null);

        $booking = $booking->fresh();
        $this->assertSame($this->user(self::REPRESENTATIVE)->id, (int) $booking->handler_user_id);
        $this->assertNotNull($booking->handler_assigned_at);
        $this->assertSame('service_desk_handling', $this->as(self::REPRESENTATIVE)->getJson($this->url($booking))->json('data.stage'));

        // Back to the queue without a comment: allowed, and the handler is released.
        $this->as(self::REPRESENTATIVE)->postJson($this->url($booking, '/status'), ['status_id' => 1, 'version' => 2])
            ->assertOk()->assertJsonPath('data.status_id', 1);

        $booking = $booking->fresh();
        $this->assertNull($booking->handler_user_id);
        $this->assertNull($booking->handler_assigned_at);

        // Taken again and closed as done; closed means closed.
        $this->as(self::REPRESENTATIVE)->postJson($this->url($booking, '/status'), ['status_id' => 151, 'version' => 3])->assertOk();
        $this->as(self::REPRESENTATIVE)->postJson($this->url($booking, '/status'), ['status_id' => 5, 'version' => 4])->assertOk();

        $detail = $this->as(self::REPRESENTATIVE)->getJson($this->url($booking))->json('data');
        $this->assertSame('closed', $detail['stage']);
        $this->assertSame([], $detail['actions']);

        // A short request may be closed straight from the queue, without taking it.
        $short = $this->request();
        $this->as(self::REPRESENTATIVE)->postJson($this->url($short, '/status'), ['status_id' => 5, 'version' => 1])->assertOk();
        $this->assertNull($short->fresh()->handler_user_id);
    }

    public function test_closing_without_doing_needs_a_comment_and_reopening_is_the_commanders(): void
    {
        $booking = $this->request();

        // Closing without doing the work explains itself.
        $this->as(self::REPRESENTATIVE)->postJson($this->url($booking, '/status'), ['status_id' => 7, 'version' => 1])
            ->assertUnprocessable()->assertJsonPath('message', 'יש לצרף הערה לביצוע פעולה זו');
        $this->as(self::REPRESENTATIVE)->postJson($this->url($booking, '/status'), ['status_id' => 7, 'version' => 1, 'comment' => 'אין צורך בהתקנה'])
            ->assertOk();

        // The representative does not reopen; the commander does, with a reason.
        $this->as(self::REPRESENTATIVE)->postJson($this->url($booking, '/status'), ['status_id' => 151, 'version' => 2, 'comment' => 'פתיחה'])
            ->assertForbidden();
        $this->as(self::COMMANDER)->postJson($this->url($booking, '/status'), ['status_id' => 151, 'version' => 2])
            ->assertUnprocessable()->assertJsonPath('message', 'יש לצרף הערה לביצוע פעולה זו');
        $this->as(self::COMMANDER)->postJson($this->url($booking, '/status'), ['status_id' => 151, 'version' => 2, 'comment' => 'נבדק שוב'])
            ->assertOk()->assertJsonPath('data.status_id', 151);

        // The super admin, as the type's admin, holds every move - the reopen included.
        $this->as(self::SUPER_ADMIN)->postJson($this->url($booking, '/status'), ['status_id' => 5, 'version' => 3])->assertOk();
        $this->as(self::SUPER_ADMIN)->postJson($this->url($booking, '/status'), ['status_id' => 151, 'version' => 4, 'comment' => 'בדיקה חוזרת'])->assertOk();

        // The requester never reaches the management side (the middleware stops the plain user role), and the camp roles do not see the desk.
        $other = $this->request();
        $this->as(self::PLAIN_USER)->getJson($this->url($other))->assertForbidden();
        $this->as(self::ADMIN)->getJson($this->url($other))->assertNotFound();
        $this->as(self::APPROVER)->getJson($this->url($other))->assertNotFound();
    }

    public function test_what_each_desk_person_is_told_about_themself(): void
    {
        $commander = $this->as(self::COMMANDER)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertTrue($commander['is_ict_admin']);
        $this->assertFalse($commander['is_admin']);
        $this->assertFalse($commander['is_handler_only']);
        $this->assertEqualsCanonicalizing([20, 21, 22, 23, 24, 25], $commander['managed_booking_types']);
        $this->assertEqualsCanonicalizing([20, 21, 22, 23, 24, 25], $commander['route_owner_booking_types']);
        $this->assertEqualsCanonicalizing([20, 21, 22, 23, 24, 25], $commander['dashboard_booking_types']);
        $this->assertTrue($commander['capabilities']['dashboard.view']);
        $this->assertTrue($commander['capabilities']['users.manage']);
        $this->assertTrue($commander['capabilities']['reports.view']);
        $this->assertFalse($commander['capabilities']['config.global']);

        // The representative acts on the ICT types among the rest; their /me does not call them an ICT commander.
        $representative = $this->as(self::REPRESENTATIVE)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertFalse($representative['is_ict_admin']);
        $this->assertContains(20, $representative['managed_booking_types']);
        $this->assertNotContains(20, $representative['route_owner_booking_types']);

        // The catalog tells the screens about the desk's route.
        $catalog = $this->as(self::COMMANDER)->getJson(self::BASE . '/catalog')->assertOk()->json('data');
        $this->assertSame('ict_service_desk', $catalog['routes']['20']['key']);
        $this->assertSame([1, 151, 5], array_column($catalog['routes']['20']['steps'], 'status_id'));
        $this->assertSame('admin_ict', $catalog['routes']['20']['owner_role']['name']);
        $this->assertSame('user', $catalog['routes']['20']['requester_role']['name']);
        $this->assertEqualsCanonicalizing([1, 151, 5, 6, 7], $catalog['booking_type_statuses']['20']);
    }

    //----------------------------- Appointing the desk's people ----------------//

    public function test_the_commander_appoints_representatives_on_the_ict_types_only(): void
    {
        // The commander's permissions screen holds the ICT types alone.
        $screen = $this->as(self::COMMANDER)->getJson('/api/bookings-admin-management/get-approver-permissions/' . $this->user(self::NO_ROLE)->uuid)
            ->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([20, 21, 22, 23, 24, 25], array_column($screen['permissions'], 'booking_type_id'));
        $this->assertArrayHasKey('is_vice_approver', $screen['permissions'][0]);
        $this->assertArrayHasKey('is_user', $screen['permissions'][0]);
        $this->assertArrayNotHasKey('is_admin_ict', $screen['permissions'][0], 'the commander role is the super admin\'s to give');

        // Appointing a representative on an ICT type works; on a camp type it is outside the commander's scope.
        $this->as(self::COMMANDER)->postJson('/api/bookings-admin-management/assign-approver-role', [
            'uuid' => $this->user(self::NO_ROLE)->uuid,
            'role_assignments' => [['booking_type_id' => self::SOFTWARE_INSTALL, 'is_vice_approver' => true, 'is_user' => true]],
        ])->assertOk();

        $appointed = $this->user(self::NO_ROLE);
        $this->assertTrue($appointed->hasRole('vice_approver'));
        $this->assertTrue($appointed->hasPermissionTo('vice_approver_' . self::SOFTWARE_INSTALL));
        $this->assertTrue($appointed->hasPermissionTo('user_' . self::SOFTWARE_INSTALL));

        $this->as(self::COMMANDER)->postJson('/api/bookings-admin-management/assign-approver-role', [
            'uuid' => $this->user(self::OTHER_PLAIN_USER)->uuid,
            'role_assignments' => [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => true]],
        ])->assertForbidden();

        // Nobody but the super admin touches a commander: not the camp admin, and the commander touches no camp admin.
        $this->as(self::ADMIN)->postJson('/api/bookings-admin-management/assign-approver-role', [
            'uuid' => $this->user(self::COMMANDER)->uuid,
            'role_assignments' => [['booking_type_id' => 1, 'is_approver' => false, 'is_vice_approver' => true]],
        ])->assertForbidden();
        $this->as(self::COMMANDER)->postJson('/api/bookings-admin-management/assign-approver-role', [
            'uuid' => $this->user(self::ADMIN)->uuid,
            'role_assignments' => [['booking_type_id' => self::SOFTWARE_INSTALL, 'is_vice_approver' => true]],
        ])->assertForbidden();
    }

    public function test_the_super_admin_appoints_the_commander_and_the_flags_keep_to_their_types(): void
    {
        $person = $this->user(self::OTHER_PLAIN_USER);

        // The commander flag is granted on an ICT type; on a camp type it is refused, as is the camp flag on an ICT type.
        $this->as(self::SUPER_ADMIN)->postJson('/api/bookings-super-admin-management/assign-admin-role', [
            'uuid' => $person->uuid,
            'role_assignments' => [['booking_type_id' => self::SOFTWARE_INSTALL, 'is_admin_ict' => true]],
        ])->assertOk();

        $person = $person->fresh();
        $this->assertTrue($person->hasRole('admin_ict'));
        $this->assertTrue($person->hasPermissionTo('admin_ict_' . self::SOFTWARE_INSTALL));

        $this->as(self::SUPER_ADMIN)->postJson('/api/bookings-super-admin-management/assign-admin-role', [
            'uuid' => $person->uuid,
            'role_assignments' => [['booking_type_id' => 1, 'is_admin_ict' => true]],
        ])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->postJson('/api/bookings-super-admin-management/assign-admin-role', [
            'uuid' => $person->uuid,
            'role_assignments' => [['booking_type_id' => self::SOFTWARE_INSTALL, 'is_admin' => true]],
        ])->assertUnprocessable();

        // The screen says which flag each type answers: the camp flag on a camp type, the commander flag on an ICT type.
        $screen = $this->as(self::SUPER_ADMIN)->getJson('/api/bookings-super-admin-management/get-admin-permissions/' . $person->uuid)
            ->assertOk()->json('data');
        $rows = collect($screen['permissions'])->keyBy('booking_type_id');
        $this->assertNull($rows[self::SOFTWARE_INSTALL]['is_admin']);
        $this->assertTrue($rows[self::SOFTWARE_INSTALL]['is_admin_ict']);
        $this->assertNull($rows[1]['is_admin_ict']);
        $this->assertFalse($rows[1]['is_admin']);
        $this->assertSame(RoleEnum::ADMIN_ICT->value, $screen['role_options']['admin_ict']);

        // Taking the last commander grant away takes the role with it - and only that role.
        $this->as(self::SUPER_ADMIN)->postJson('/api/bookings-super-admin-management/assign-admin-role', [
            'uuid' => $person->uuid,
            'role_assignments' => [['booking_type_id' => self::SOFTWARE_INSTALL, 'is_admin_ict' => false]],
        ])->assertOk();
        $this->assertFalse($person->fresh()->hasRole('admin_ict'));
    }

    //----------------------------- The forms -----------------------------------//

    public function test_the_printer_ip_is_required_only_for_a_connected_printer(): void
    {
        $network = $this->field(self::PRINTER_INSTALL, 'network');
        $computer = $this->field(self::PRINTER_INSTALL, 'computer_name');
        $model = $this->field(self::PRINTER_INSTALL, 'printer_model');
        $installType = $this->field(self::PRINTER_INSTALL, 'install_type');
        $ip = $this->field(self::PRINTER_INSTALL, 'printer_ip');

        $base = [
            (string) $network->id => $this->optionId($network, 'ARMY'),
            (string) $computer->id => 'DESK-PC-01',
            (string) $model->id => (int) OptionField::query()->where('form_field_id', $model->id)->value('id'),
        ];

        // A new printer needs no IP; one sent anyway is not stored (the condition does not hold).
        $new = $base + [(string) $installType->id => $this->optionId($installType, 'מדפסת חדשה'), (string) $ip->id => '10.0.0.7'];
        $uuid = $this->as(self::PLAIN_USER)->postJson('/api/bookings/' . self::PRINTER_INSTALL, ['values' => $new])
            ->assertCreated()->json('data.uuid');
        $this->assertNull($this->valueOf(Booking::where('uuid', $uuid)->firstOrFail(), $ip));

        // A connected printer demands it, in its own format.
        $connected = $base + [(string) $installType->id => $this->optionId($installType, 'מדפסת מחוברת')];
        $this->as(self::PLAIN_USER)->postJson('/api/bookings/' . self::PRINTER_INSTALL, ['values' => $connected])
            ->assertUnprocessable()->assertJsonValidationErrors(["values.{$ip->id}"]);

        $uuid = $this->as(self::PLAIN_USER)->postJson('/api/bookings/' . self::PRINTER_INSTALL, ['values' => $connected + [(string) $ip->id => '10.0.0.7']])
            ->assertCreated()->json('data.uuid');
        $this->assertSame('10.0.0.7', $this->valueOf(Booking::where('uuid', $uuid)->firstOrFail(), $ip));
    }

    public function test_the_user_open_lookup_overrides_the_locked_fields_but_keeps_the_typed_phone(): void
    {
        // The person the form looks up (the users table is the lookup's source; Vatican is not configured in tests).
        User::factory()->create([
            'personal_id' => '305000001',
            'personal_number' => '7000333',
            'full_name' => 'ישראל כהן',
            'phone' => '0501111111',
            'unit_id' => UnitEnum::H->value,
        ]);

        $fields = [];

        foreach (['personal_id', 'personal_number', 'first_name', 'last_name', 'rank', 'phone', 'department', 'branch', 'section', 'role', 'network', 'security_declaration'] as $name) {
            $fields[$name] = $this->field(self::USER_OPEN, $name);
        }

        $values = [
            (string) $fields['personal_id']->id => '305000001',
            // Tampered in the browser: the server looks the person up again and overwrites the locked fields.
            (string) $fields['personal_number']->id => '9999999',
            (string) $fields['first_name']->id => 'זיוף',
            (string) $fields['last_name']->id => 'מזויף',
            (string) $fields['rank']->id => 'רס"ל',
            // Editable autofill: what the requester typed stays.
            (string) $fields['phone']->id => '0529999999',
            (string) $fields['department']->id => 'מחלקת תקשוב',
            (string) $fields['branch']->id => 'ענף תשתיות',
            (string) $fields['section']->id => 'מדור רשתות',
            (string) $fields['role']->id => 'מנהל רשת',
            (string) $fields['network']->id => $this->optionId($fields['network'], 'ARMY'),
            (string) $fields['security_declaration']->id => '1',
        ];

        $uuid = $this->as(self::PLAIN_USER)->postJson('/api/bookings/' . self::USER_OPEN, ['values' => $values])
            ->assertCreated()->json('data.uuid');
        $booking = Booking::where('uuid', $uuid)->firstOrFail();

        $this->assertSame('7000333', $this->valueOf($booking, $fields['personal_number']), 'locked: the lookup wins');
        $this->assertSame('ישראל', $this->valueOf($booking, $fields['first_name']));
        $this->assertSame('כהן', $this->valueOf($booking, $fields['last_name']));
        $this->assertSame('0529999999', $this->valueOf($booking, $fields['phone']), 'editable: the requester wins');
        $this->assertSame('רס"ל', $this->valueOf($booking, $fields['rank']), 'no source holds the rank yet');

        // The form tells the screen how to draw it: the lookup trigger, the locks and the editable phone.
        $form = $this->as(self::PLAIN_USER)->getJson('/api/requester/form/' . self::USER_OPEN)->assertOk()->json('data');
        $byName = collect($form)->keyBy('field_name');
        $this->assertSame('person', $byName['personal_id']['lookup_source']);
        $this->assertTrue($byName['first_name']['autofill_locked']);
        $this->assertFalse($byName['phone']['autofill_locked']);
        $this->assertSame('phone', $byName['phone']['autofill_key']);
    }
}

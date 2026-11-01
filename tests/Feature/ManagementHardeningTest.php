<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingRestrictionDate;
use App\Models\BookingTypeHasStatement;
use App\Models\BookingValue;
use App\Models\OptionField;
use App\Models\SystemReference;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the independent review of the management layer found: the settings of one booking type are not open to a role held on another, a
 * handler is a handler per booking type, an admin deals with the people of their own unit only (and not with themselves), and the
 * settings that were said to be audited are.
 */
class ManagementHardeningTest extends ManagementTestCase
{
    private const BASE = '/api/bookings-approver-management';
    private const SUPER = '/api/bookings-super-admin-management';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    private function booking(int $serial, int $unitId, int $bookingTypeId, int $statusId): Booking
    {
        return Booking::create([
            'serial_number' => $serial,
            'unit_id' => $unitId,
            'booking_type_id' => $bookingTypeId,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => $statusId,
            'ext_status_id' => $statusId,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);
    }

    /** A car booking (type 1) that sits on $date. */
    private function carBookingOn(int $serial, int $unitId, string $date): Booking
    {
        $booking = $this->booking($serial, $unitId, 1, 15);

        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 2, 'value' => $date]);
        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 4, 'value' => $date]);

        return $booking;
    }

    private function workingDay(int $daysFromToday): string
    {
        $day = now()->addDays($daysFromToday);

        while ($day->isFriday() || $day->isSaturday()) {
            $day = $day->addDay();
        }

        return $day->toDateString();
    }

    private function carRestrictionDatesReferenceId(): int
    {
        return (int) SystemReference::where('name', 'restriction_dates_car_booking')->where('unit_id', 1)->value('id');
    }

    /** A person of unit H whose only permission is vice approver of one booking type. */
    private function viceApproverOfOnly(int $bookingTypeId, string $personalNumber = '1000010'): User
    {
        $user = User::factory()->create(['personal_number' => $personalNumber, 'unit_id' => 1]);
        $user->assignRole(RoleEnum::VICE_APPROVER->value);
        $user->givePermissionTo("vice_approver_{$bookingTypeId}");

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    //----------------------------- Settings of another booking type ----------------//

    public function test_a_role_held_on_one_booking_type_does_not_open_the_settings_of_another(): void
    {
        $date = $this->workingDay(12);
        $booking = $this->carBookingOn(800000001, 1, $date);
        $this->viceApproverOfOnly(5);

        // A vice approver of the barber blocks a date of the cars: nothing is saved, nothing is canceled, and the attempt is on record.
        $this->as('1000010')
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$this->carRestrictionDatesReferenceId() => ['dates' => [$date]]]])
            ->assertForbidden();

        $this->assertSame(0, BookingRestrictionDate::count());
        $this->assertSame(15, (int) $booking->fresh()->status_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'config.system_reference.update', 'result' => 'denied', 'entity_type' => 'booking_type', 'entity_id' => '1']);
    }

    public function test_a_change_of_a_reference_is_audited_with_the_values_before_and_after(): void
    {
        $referenceId = (int) SystemReference::where('name', 'contact_phone_car_booking')->where('unit_id', 1)->value('id');
        $before = SystemReference::findOrFail($referenceId)->decodedValue()['contact_phone'];

        $this->as(self::APPROVER)
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$referenceId => ['contact_phone' => '0501234567']]])
            ->assertOk();

        $row = AuditLog::where('action', 'config.system_reference.update')->firstOrFail();
        $this->assertSame('success', $row->result);
        $this->assertSame((string) $referenceId, $row->entity_id);
        $this->assertSame(1, (int) $row->booking_type_id);
        $this->assertSame($before, $row->before['contact_phone']);
        $this->assertSame('0501234567', $row->after['contact_phone']);

        // The same value again is no change, so there is nothing to record.
        $this->as(self::APPROVER)
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$referenceId => ['contact_phone' => '0501234567']]])
            ->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'config.system_reference.update')->count());
    }

    public function test_blocking_a_date_is_audited_as_a_list_of_dates(): void
    {
        $date = $this->workingDay(12);
        $this->as(self::APPROVER)
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$this->carRestrictionDatesReferenceId() => ['dates' => [$date]]]])
            ->assertOk();

        $row = AuditLog::where('action', 'config.system_reference.update')->firstOrFail();
        $this->assertSame([], $row->before['dates']);
        $this->assertSame([$date], $row->after['dates']);
    }

    public function test_the_impact_counts_the_bookings_of_every_unit_but_names_only_the_users_own(): void
    {
        $date = $this->workingDay(12);
        $this->carBookingOn(800000001, 1, $date);
        $this->carBookingOn(800000002, 2, $date);
        $referenceId = $this->carRestrictionDatesReferenceId();
        $url = self::BASE . "/settings/references/{$referenceId}/impact";

        // The list of blocked dates belongs to the booking type: the booking of the other unit is canceled too.
        $own = $this->as(self::APPROVER)->postJson($url, ['dates' => [$date]])->assertOk()->json('data');
        $this->assertSame(2, $own['affected_count']);
        $this->assertSame([800000001], $own['affected_serials']);
        $this->assertSame(1, $own['affected_in_other_units']);

        $all = $this->as(self::SUPER_ADMIN)->postJson($url, ['dates' => [$date]])->assertOk()->json('data');
        $this->assertSame(2, $all['affected_count']);
        $this->assertSame([800000001, 800000002], $all['affected_serials']);
        $this->assertSame(0, $all['affected_in_other_units']);
    }

    //----------------------------- Handler per booking type ------------------------//

    public function test_a_handler_sees_every_stage_of_a_type_they_lead_and_only_their_own_stage_of_the_others(): void
    {
        // A vice approver of the in-unit food booking (11) who is also the approver of the cars (1).
        $user = $this->viceApproverOfOnly(11);
        $user->assignRole(RoleEnum::APPROVER->value);
        $user->givePermissionTo('approver_1');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Booking::query()->delete();
        $this->booking(900001, 1, 1, 3);    // a car booking already approved: the approver stage of the cars, theirs to see
        $this->booking(900002, 1, 11, 3);   // a food booking already approved: not the turn of a vice approver
        $this->booking(900003, 1, 11, 1);   // a food booking just received: the vice approver's turn

        $serials = collect($this->as('1000010')->getJson(self::BASE . '/bookings')->assertOk()->json('data'))->pluck('serial_number')->sort()->values()->all();

        $this->assertSame([900001, 900003], $serials);
        $this->as('1000010')->getJson(self::BASE . '/bookings/900002')->assertNotFound();
        $this->as('1000010')->getJson(self::BASE . '/bookings/900001')->assertOk();

        // The screen is not "queue only": they lead a domain.
        $this->assertFalse($this->as('1000010')->getJson(self::BASE . '/me')->assertOk()->json('data.is_handler_only'));
    }

    //----------------------------- People: admin and unit --------------------------//

    public function test_an_admin_cannot_change_their_own_roles(): void
    {
        $admin = User::where('personal_number', self::ADMIN)->firstOrFail();

        $this->as(self::ADMIN)
            ->postJson('/api/bookings-admin-management/assign-approver-role', [
                'uuid' => $admin->uuid,
                'role_assignments' => [['booking_type_id' => 1, 'is_approver' => true, 'is_vice_approver' => false]],
            ])
            ->assertForbidden();

        $this->assertFalse($admin->fresh()->getDirectPermissions()->contains('name', 'approver_1'));
        $this->assertDatabaseHas('audit_log', ['action' => 'user.permissions.change', 'result' => 'denied', 'reason' => 'self_grant']);
    }

    public function test_an_admin_sees_the_people_of_their_own_unit_only(): void
    {
        User::factory()->create(['personal_number' => '2000020', 'unit_id' => 2, 'full_name' => 'אורח מיחידה אחרת']);

        // Looking a person up by number: the admin finds those of their unit, the super admin everybody.
        $this->as(self::ADMIN)->getJson('/api/users/' . self::APPROVER)->assertOk()->assertJsonCount(1, 'data');
        $this->as(self::ADMIN)->getJson('/api/users/2000020')->assertOk()->assertJsonCount(0, 'data');
        $this->as(self::SUPER_ADMIN)->getJson('/api/users/2000020')->assertOk()->assertJsonCount(1, 'data');

        // The permissions of a person of another unit are not shown to the admin either.
        $other = User::where('personal_number', '2000020')->firstOrFail();
        $this->as(self::ADMIN)->getJson("/api/bookings-admin-management/get-approver-permissions/{$other->uuid}")->assertStatus(400);
        $this->as(self::SUPER_ADMIN)->getJson("/api/bookings-admin-management/get-approver-permissions/{$other->uuid}")->assertOk();
    }

    //----------------------------- Settings that are audited -----------------------//

    public function test_the_statement_of_a_booking_type_is_audited(): void
    {
        $this->as(self::SUPER_ADMIN)
            ->putJson(self::SUPER . '/update-statement/1/1', ['statement' => 'הצהרה חדשה', 'is_active' => true])
            ->assertOk();

        $row = AuditLog::where('action', 'config.statement.update')->firstOrFail();
        $this->assertSame('1:1', $row->entity_id);
        $this->assertSame('הצהרה חדשה', $row->after['statement']);
        $this->assertTrue($row->after['is_active']);
        $this->assertSame(1, BookingTypeHasStatement::where('unit_id', 1)->where('booking_type_id', 1)->count());
    }

    public function test_adding_and_removing_an_option_is_audited_with_what_changed(): void
    {
        // Field 7 is the select of the in-unit food booking.
        $response = $this->as(self::SUPER_ADMIN)
            ->putJson(self::SUPER . '/update-option/7', ['option_name' => 'אפשרות חדשה', 'has_category' => 0])
            ->assertOk();

        $optionId = (int) $response->json('data.option_id');
        $added = AuditLog::where('action', 'config.options.update')->firstOrFail();
        $this->assertSame('7', $added->entity_id);
        $this->assertSame([], $added->before);
        $this->assertSame('אפשרות חדשה', $added->after[(string) $optionId]['name']);
        $this->assertCount(1, $added->after, 'only the option that was added, not the whole list');

        $this->as(self::SUPER_ADMIN)
            ->deleteJson(self::SUPER . '/remove-option/7', ['option_id' => $optionId])
            ->assertOk();

        $removed = AuditLog::where('action', 'config.options.remove')->firstOrFail();
        $this->assertSame('אפשרות חדשה', $removed->before[(string) $optionId]['name']);
        $this->assertSame([], $removed->after);
        $this->assertNull(OptionField::find($optionId));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\User;
use App\Services\UsersExportService;
use Illuminate\Testing\TestResponse;

/**
 * The OUT-unit food booking (type 10) runs the three-round flow of transportation (the mobile's 2026-11-25 switch
 * moved it from in-unit, which is back on the standard route): רמ"ד תו"ב (role 7, out_unit_food_control_officer_10)
 * answers first (17/18), the vice approver passes it on (15/16), the approver decides - without the supplier holds,
 * which stay transportation-only. And status 19 (ממתין לגורם חיצוני, types 3/4/8/10/11/13): every managing role may
 * park a booking there, only the approver or the admin closes it (3/4), nothing moves back.
 */
class OutUnitFoodControlOfficerTest extends HatchTestCase
{
    private const SERIAL = 6801;
    private const LIST = '/api/bookings-approver-management/bookings';

    private const OFFICER = '1000009';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** A booking as the mobile app left it (type 10 unless told otherwise). */
    private function booking(int $statusId = 1, int $bookingTypeId = 10, array $attributes = []): Booking
    {
        return Booking::create($attributes + [
            'serial_number' => self::SERIAL,
            'unit_id' => 1,
            'booking_type_id' => $bookingTypeId,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => $statusId,
            'ext_status_id' => $statusId,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);
    }

    /** The control officer of the out-unit food type (role 7). */
    private function controlOfficer(): string
    {
        $officer = User::factory()->create(['personal_number' => self::OFFICER, 'unit_id' => 1, 'personal_id' => '300000221']);
        $officer->assignRole(RoleEnum::OUT_UNIT_FOOD_CONTROL_OFFICER->value);
        $officer->givePermissionTo('out_unit_food_control_officer_10');

        return self::OFFICER;
    }

    private function move(string $person, int $statusId, int $version, ?string $comment = null, int $serial = self::SERIAL): TestResponse
    {
        return $this->as($person)->postJson("/api/bookings-approver-management/bookings/{$serial}/status", ['status_id' => $statusId, 'version' => $version] + ($comment === null ? [] : ['comment' => $comment]));
    }

    /** @return ?array<string, mixed> the booking's row in this person's list, or null when they do not see it */
    private function row(string $person, int $serial = self::SERIAL): ?array
    {
        return $this->as($person)->getJson(self::LIST . '?ids=' . $serial)->assertOk()->json('data.0');
    }

    /** @return ?array<int, int> the status ids of this person's buttons on the booking */
    private function buttons(string $person, int $serial = self::SERIAL): ?array
    {
        $row = $this->row($person, $serial);

        return $row === null ? null : array_map('intval', array_column($row['actions'], 'status_id'));
    }

    //----------------------------- The three-round route of type 10 ------------------------//

    public function test_the_out_unit_food_route_starts_at_the_control_officer(): void
    {
        $booking = $this->booking();
        $officer = $this->controlOfficer();

        // Received: the control officer's turn - answer (17/18) or park for an external party (19). No supplier holds here.
        $row = $this->row($officer);
        $this->assertSame('control_officer', $row['stage']);
        $this->assertSame([17, 18, 19], $this->buttons($officer));

        // The other roles around the officer's turn: the vice approver may take it over, the approver and the admin decide.
        $this->assertSame([15, 16, 19], $this->buttons(self::VICE_APPROVER));
        $this->assertSame([3, 4, 19], $this->buttons(self::APPROVER));
        $this->assertSame([17, 18, 19, 3, 4], $this->buttons(self::ADMIN));

        // The officer answers; from then on the booking is the vice approver's, then the approver's, as on transportation.
        $this->move($officer, 17, 1)->assertOk()->assertJsonPath('data.status_id', 17)->assertJsonPath('data.sync_state', 'pending');
        $this->assertNull($this->row($officer), 'the control officer is done with it');
        $this->assertSame('vice_approver', $this->row(self::VICE_APPROVER)['stage']);
        $this->assertSame([15, 16, 19], $this->buttons(self::VICE_APPROVER));

        $this->move(self::VICE_APPROVER, 15, 2)->assertOk();
        $this->assertSame([3, 4, 19], $this->buttons(self::APPROVER));

        $this->move(self::APPROVER, 3, 3)->assertOk();
        $this->assertSame([5, 7], $this->buttons(self::APPROVER), 'after the final approval only the closing moves are left');

        $this->assertSame([17, 15, 3], ApplicationLog::where('booking_id', $booking->id)->orderBy('id')->pluck('status_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_the_car_booking_route_is_untouched_and_without_the_external_wait(): void
    {
        $this->booking(1, 1);

        // Type 1 has no control officer and no status 19: the vice approver's turn, the approver's skip, nothing else.
        $this->assertSame('vice_approver', $this->row(self::VICE_APPROVER)['stage']);
        $this->assertNull($this->row(self::VICE_APPROVER)['parked_status_id'], 'not parked: the field is null on an ordinary booking');
        $this->assertSame([15, 16], $this->buttons(self::VICE_APPROVER));
        $this->assertSame([3, 4], $this->buttons(self::APPROVER));
        $this->assertSame([15, 16, 3, 4], $this->buttons(self::ADMIN));
    }

    //----------------------------- Status 19: ממתין לגורם חיצוני ------------------------//

    public function test_parked_for_an_external_party_only_the_approver_or_the_admin_closes(): void
    {
        $booking = $this->booking();
        $officer = $this->controlOfficer();

        // The control officer parks the booking for an outside party's answer.
        $this->move($officer, 19, 1)->assertOk()->assertJsonPath('data.status_id', 19);

        $booking = $booking->fresh();
        $this->assertSame(19, (int) $booking->status_id);
        // The park RECORDS where it was sent from (the screens draw the timeline split from it); the turn still moves on -
        // 19 is not a hold, so positionStatus() ignores the park and the approver is next.
        $this->assertSame(1, (int) $booking->parked_status_id, 'entered the external wait from the officer stage');
        $this->assertFalse($booking->is_status_exported, 'the next round of the r2b status file carries it');
        $this->assertSame(14, (int) ApplicationLog::where('booking_id', $booking->id)->latest('id')->value('action_type_id'), 'move_to_waiting_external_party');

        // Only the approver and the admin act now: the final approval or the final rejection, nothing back, no re-park.
        $this->assertSame('external_party', $this->row(self::APPROVER)['stage']);
        $this->assertSame(1, $this->row(self::APPROVER)['parked_status_id'], 'the list rows carry where it was parked from');
        $this->assertSame([3, 4], $this->buttons(self::APPROVER));
        $this->assertSame([3, 4], $this->buttons(self::ADMIN));

        // The external wait is past the lower stages: the booking leaves the vice approver's and the officer's view entirely.
        $this->move(self::VICE_APPROVER, 15, 2)->assertNotFound();
        $this->move($officer, 17, 2)->assertNotFound();
        $this->assertNull($this->row($officer));
        $this->move(self::APPROVER, 1, 2)->assertForbidden();
        $this->move(self::APPROVER, 19, 2)->assertForbidden();

        $this->assertTrue($this->mayEditValues(self::APPROVER, self::SERIAL), 'the managing roles may still correct values at 19');
        $this->assertFalse($this->mayEditValues(self::VICE_APPROVER, self::SERIAL));

        $this->move(self::APPROVER, 3, 2)->assertOk();
        $booking = $booking->fresh();
        $this->assertSame(3, (int) $booking->status_id);
        $this->assertNull($booking->parked_status_id, 'closing the external wait clears the park');
    }

    public function test_a_transportation_hold_moved_to_the_external_wait_keeps_the_park(): void
    {
        // A transportation booking on a supplier hold, parked at the vice approver's stage.
        $booking = $this->booking(9, 8, ['parked_status_id' => 17]);

        // The admin may park it for the external party straight from the hold; the park travels with it (the station that
        // held it is the timeline's split point) - but from 19 only 3/4 follow, exactly as before.
        $this->assertContains(19, $this->buttons(self::ADMIN));
        $this->move(self::ADMIN, 19, 1)->assertOk();

        $booking = $booking->fresh();
        $this->assertSame(19, (int) $booking->status_id);
        $this->assertSame(17, (int) $booking->parked_status_id, 'the hold was parked at 17 - the external wait keeps that origin');
        $this->assertSame([3, 4], $this->buttons(self::APPROVER), 'no way back to the holds from the external wait');
    }

    //----------------------------- The users file and the mirror fields ------------------------//

    public function test_the_users_file_carries_role_7_for_the_out_unit_food_control_officer(): void
    {
        $this->controlOfficer();

        $rows = app(UsersExportService::class)->payload();
        $row = collect($rows)->firstWhere('personal_id', '300000221');

        $this->assertNotNull($row, 'the officer waits for the next users file');
        $this->assertContains(['role_id' => RoleEnum::OUT_UNIT_FOOD_CONTROL_OFFICER->value, 'booking_type_ids' => [10]], $row['booking_type_roles']);
        $this->assertSame(7, RoleEnum::OUT_UNIT_FOOD_CONTROL_OFFICER->value, 'the id the mobile app resolves');
    }

    public function test_the_mobile_owned_handler_fields_sit_at_the_exact_mobile_ids(): void
    {
        $fields = FormField::query()->where('field_name', 'like', FormField::MOBILE_OWNED_PREFIX . '%')->orderBy('id')->get();

        // The b2r booking files carry these raw ids and the import resolves them BY ID, so they are pinned:
        // fields 106 (out-unit food, type 10 since the flow switch) and 107 (transportation, type 8), options 351-356 / 357-362 in mobile order.
        $this->assertSame(
            [106 => 'vice_approver_handler_out_unit_food_booking', 107 => 'vice_approver_handler_transportation_booking'],
            $fields->pluck('field_name', 'id')->all()
        );
        $this->assertSame(
            [106 => 10, 107 => 8],
            $fields->pluck('booking_type_id', 'id')->map(fn ($id): int => (int) $id)->all()
        );

        foreach ($fields as $field) {
            $this->assertFalse((bool) $field->is_active, 'never offered on the web forms');
            $this->assertSame('בחירת רמ"ד תו"ב', $field->field_display_name);
        }

        // Six options per field since the 2026-11-25 flow switch ('ליד' joined the groups), laid as the fresh mobile seed lays them.
        $this->assertSame(
            [
                351 => 'משקים ומטה', 352 => 'גבולות וחשיפה', 353 => 'תקיפה ואש', 354 => 'רובוטיקה ותמרון', 355 => 'אדרת', 356 => 'ליד',
                357 => 'משקים ומטה', 358 => 'גבולות וחשיפה', 359 => 'תקיפה ואש', 360 => 'רובוטיקה ותמרון', 361 => 'אדרת', 362 => 'ליד',
            ],
            OptionField::whereIn('form_field_id', [106, 107])->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all(),
            'the incoming option_id resolves one-to-one'
        );
        $this->assertSame(
            [351 => 106, 352 => 106, 353 => 106, 354 => 106, 355 => 106, 356 => 106, 357 => 107, 358 => 107, 359 => 107, 360 => 107, 361 => 107, 362 => 107],
            OptionField::whereIn('form_field_id', [106, 107])->orderBy('id')->pluck('form_field_id', 'id')->map(fn ($id): int => (int) $id)->all(),
            'the 106/107 boundary moved again: 356 is ליד of field 106 now, the transport block starts at 357'
        );
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Clearance\ClearanceRegistry;
use App\Domain\Workflow\Routes\ClearanceGrantFields;
use App\Enums\UnitEnum;
use App\Models\ApplicationLog;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\SyncFile;
use App\Models\User;
use App\Services\BookingStatusSnapshotService;
use App\Services\UsersExportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * The route of the clearance grant (type 14): assignment of the handler, the scheduling move with its handler fields, the automatic
 * transitions, the moves back and the refusal, who sees what, and that nothing of it reaches the mobile side.
 */
class ClearanceGrantWorkflowTest extends ManagementTestCase
{
    private const TYPE = 14;
    private const BASE = '/api/bookings-approver-management';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDomainPeople();
    }

    /** Send the next request as another person (the test client keeps the guard of the one before). */
    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    //----------------------------- Fixtures ------------------------------------//

    /** A request of the קב"ט at the start of the route, with the requester values the route reads (ID number, requested level). */
    private function request(array $attributes = [], string $requestedLevelCode = 'secret'): Booking
    {
        static $serial = 800000300;

        $booking = Booking::create($attributes + [
            'serial_number' => ++$serial,
            'unit_id' => UnitEnum::H->value,
            'booking_type_id' => self::TYPE,
            'user_id' => User::where('personal_number', self::SECURITY_OFFICER)->value('id'),
            'status_id' => 101,
            'status_source' => 'web',
            'status_changed_at' => now(),
            'version' => 1,
            'is_status_exported' => true,
        ]);

        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => $this->field(ClearanceGrantFields::PERSONAL_ID)->id, 'value' => '300000199']);
        BookingValue::create([
            'booking_id' => $booking->id,
            'form_field_id' => $this->field(ClearanceGrantFields::REQUESTED_LEVEL)->id,
            'option_field_id' => OptionField::where('form_field_id', $this->field(ClearanceGrantFields::REQUESTED_LEVEL)->id)->where('code', $requestedLevelCode)->value('id'),
        ]);

        return $booking;
    }

    private function field(string $name): FormField
    {
        return FormField::query()->where('booking_type_id', self::TYPE)->where('unit_id', UnitEnum::H->value)->where('field_name', $name)->firstOrFail();
    }

    private function user(string $personalNumber): User
    {
        return User::where('personal_number', $personalNumber)->firstOrFail();
    }

    private function url(Booking $booking, string $suffix = ''): string
    {
        return self::BASE . '/bookings/' . $booking->uuid . $suffix;
    }

    /** The interview values (place, date, time) by field id, for the scheduling move. */
    private function interviewValues(?string $date = null, string $time = '10:00', string $location = 'בניין 3 חדר 12'): array
    {
        $date ??= CarbonImmutable::now('Asia/Jerusalem')->addDays(3)->toDateString();

        return [
            (string) $this->field(ClearanceGrantFields::INTERVIEW_LOCATION)->id => $location,
            (string) $this->field(ClearanceGrantFields::INTERVIEW_DATE)->id => $date,
            (string) $this->field(ClearanceGrantFields::INTERVIEW_TIME)->id => $time,
        ];
    }

    private function assign(Booking $booking, string $by, string $whom, int $version): \Illuminate\Testing\TestResponse
    {
        return $this->as($by)->putJson($this->url($booking, '/handler'), ['handler_uuid' => $this->user($whom)->uuid, 'version' => $version]);
    }

    /** A request scheduled for the interview (status 102) by the coordinator, with the handler assigned by the officer. */
    private function scheduled(?string $date = null): Booking
    {
        $booking = $this->request();
        $this->assign($booking, self::INFOSEC_OFFICER, self::INTERVIEW_COORDINATOR, 1)->assertOk();
        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 102, 'version' => 2, 'values' => $this->interviewValues($date)])->assertOk();

        return $booking->fresh();
    }

    //----------------------------- Assignment ----------------------------------//

    public function test_the_officer_assigns_a_handler_and_the_scheduling_move_waits_for_one(): void
    {
        $booking = $this->request();

        // The coordinator sees the request with the scheduling button, disabled until a handler is assigned.
        $detail = $this->as(self::INTERVIEW_COORDINATOR)->getJson($this->url($booking))->assertOk()->json('data');
        $this->assertSame('interview_assignment', $detail['stage']);
        $this->assertSame([102], array_column($detail['actions'], 'status_id'));
        $this->assertFalse($detail['actions'][0]['enabled']);
        $this->assertSame('יש להקצות מטפל לפני קביעת מועד התחקיר', $detail['actions'][0]['disabled_reason']);
        $this->assertSame([$this->field(ClearanceGrantFields::INTERVIEW_LOCATION)->id, $this->field(ClearanceGrantFields::INTERVIEW_DATE)->id, $this->field(ClearanceGrantFields::INTERVIEW_TIME)->id], $detail['actions'][0]['required_fields']);
        $this->assertTrue($detail['can_take']);
        $this->assertFalse($detail['can_assign_handler']);
        $this->assertNull($detail['handler']);
        $this->assertSame(['interview_location', 'interview_date', 'interview_time'], array_column($detail['handler_fields'], 'field_name'));
        $this->assertTrue($detail['handler_fields'][0]['can_edit']);
        $this->assertNull($detail['sync_state'], 'a web-only type has no mobile side to sync with');

        // Pressing it anyway is refused.
        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 102, 'version' => 1, 'values' => $this->interviewValues()])
            ->assertUnprocessable()->assertJsonPath('message', 'יש להקצות מטפל לפני קביעת מועד התחקיר');

        // The officer sees the refusal and the assignment; a plain user cannot be assigned; the candidates list says who can.
        $officerDetail = $this->as(self::INFOSEC_OFFICER)->getJson($this->url($booking))->assertOk()->json('data');
        $this->assertSame([102, 4], array_column($officerDetail['actions'], 'status_id'));
        $this->assertTrue($officerDetail['can_assign_handler']);
        $this->assertFalse($officerDetail['can_take']);

        $candidates = $this->as(self::INFOSEC_OFFICER)->getJson($this->url($booking, '/handler-candidates'))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([self::INFOSEC_OFFICER, self::INTERVIEW_COORDINATOR], array_column($candidates, 'personal_number'));

        $this->assign($booking, self::INFOSEC_OFFICER, self::PLAIN_USER, 1)->assertUnprocessable();
        $this->assign($booking, self::INTERVIEW_COORDINATOR, self::INFOSEC_OFFICER, 1)->assertForbidden();

        $this->assign($booking, self::INFOSEC_OFFICER, self::INTERVIEW_COORDINATOR, 1)
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.handler.uuid', $this->user(self::INTERVIEW_COORDINATOR)->uuid);

        $booking = $booking->fresh();
        $this->assertSame($this->user(self::INTERVIEW_COORDINATOR)->id, (int) $booking->handler_user_id);
        $this->assertSame(101, (int) $booking->status_id, 'assigning does not move the request');
        $this->assertDatabaseHas('application_logs', ['booking_id' => $booking->id, 'action_type_id' => 101, 'status_id' => 101]);
        $this->assertDatabaseHas('audit_log', ['action' => 'booking.handler_change', 'booking_serial' => $booking->serial_number, 'result' => 'success']);

        // Now the button is enabled, and the list filters by the handler.
        $this->assertTrue($this->as(self::INTERVIEW_COORDINATOR)->getJson($this->url($booking))->json('data.actions.0.enabled'));
        $this->assertSame([$booking->serial_number], $this->as(self::INTERVIEW_COORDINATOR)->getJson(self::BASE . '/bookings?assigned_to=me&types=14')->json('data.*.serial_number'));
        $this->assertSame([], $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings?assigned_to=me&types=14')->json('data.*.serial_number'));

        // A coordinator takes an unassigned request for themself; stale versions are refused everywhere.
        $other = $this->request();
        $this->assign($other, self::INTERVIEW_COORDINATOR, self::INTERVIEW_COORDINATOR, 1)->assertOk();
        $this->assign($other, self::INFOSEC_OFFICER, self::INFOSEC_OFFICER, 1)->assertStatus(409);
    }

    //----------------------------- Scheduling and the automatic moves -----------//

    public function test_the_scheduling_move_needs_the_interview_fields_and_sets_the_moment_the_scheduler_waits_for(): void
    {
        $booking = $this->request();
        $this->assign($booking, self::INFOSEC_OFFICER, self::INTERVIEW_COORDINATOR, 1)->assertOk();
        $dateField = (string) $this->field(ClearanceGrantFields::INTERVIEW_DATE)->id;

        // Without the values: one error per required field, under the field's key, in the move's own 422 shape.
        $errors = $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 102, 'version' => 2])
            ->assertUnprocessable()
            ->json('errors');
        $this->assertSame(array_map(fn (string $id): string => "values.{$id}", array_keys($this->interviewValues())), array_keys($errors));
        $this->assertSame(['יש לבחור תאריך לתחקיר'], $errors['values.' . $dateField]);

        // A moment that has passed.
        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 102, 'version' => 2, 'values' => $this->interviewValues(CarbonImmutable::now('Asia/Jerusalem')->subDay()->toDateString())])
            ->assertUnprocessable()->assertJsonPath('message', 'מועד התחקיר חייב להיות עתידי');

        // A requester field is not the handler's to write.
        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 102, 'version' => 2, 'values' => $this->interviewValues() + [(string) $this->field('notes')->id => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.' . $this->field('notes')->id]);

        $date = CarbonImmutable::now('Asia/Jerusalem')->addDays(3)->toDateString();
        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 102, 'version' => 2, 'comment' => null, 'values' => $this->interviewValues($date, '10:30')])
            ->assertOk()
            ->assertJsonPath('data.status_id', 102)
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.sync_state', null)
            ->assertJsonPath('data.message', 'הסטטוס עודכן.');

        $booking = $booking->fresh();
        $this->assertSame(102, (int) $booking->status_id);
        $this->assertSame('internal', $booking->status_source);
        $this->assertTrue($booking->is_status_exported, 'nothing waits for the mobile app');
        $this->assertSame(CarbonImmutable::parse("{$date} 10:30", 'Asia/Jerusalem')->utc()->toIso8601String(), $booking->stage_due_at->toIso8601String());
        $this->assertSame(3, BookingValue::where('booking_id', $booking->id)->whereIn('form_field_id', array_keys($this->interviewValues()))->count());
        $this->assertDatabaseHas('application_logs', ['booking_id' => $booking->id, 'action_type_id' => 102, 'status_id' => 102]);

        // The detail shows the handler section in input format and the progress bar at the second step.
        $detail = $this->as(self::INFOSEC_OFFICER)->getJson($this->url($booking))->assertOk()->json('data');
        $this->assertSame([$date, '10:30', 'בניין 3 חדר 12'], [
            collect($detail['handler_fields'])->firstWhere('field_name', 'interview_date')['value'],
            collect($detail['handler_fields'])->firstWhere('field_name', 'interview_time')['value'],
            collect($detail['handler_fields'])->firstWhere('field_name', 'interview_location')['value'],
        ]);
        $this->assertSame(['done', 'done', 'current', 'upcoming', 'upcoming', 'upcoming'], array_column($detail['progress'], 'state'));
        $this->assertSame([101, 4], array_column($detail['actions'], 'status_id'), 'the interview moment is the scheduler\'s: no forward button');
        $this->assertSame('handler', collect($detail['booking_values'])->firstWhere('field_name', 'interview_location')['section']);
    }

    public function test_a_reschedule_through_the_values_endpoint_recomputes_the_moment_and_stops_once_the_interview_is_over(): void
    {
        $booking = $this->scheduled();
        $newDate = CarbonImmutable::now('Asia/Jerusalem')->addDays(5)->toDateString();
        $dateField = (string) $this->field(ClearanceGrantFields::INTERVIEW_DATE)->id;

        $this->as(self::INTERVIEW_COORDINATOR)->putJson($this->url($booking, '/values'), ['values' => [$dateField => $newDate], 'version' => 3, 'reason' => 'המועמד ביקש לדחות'])
            ->assertOk()->assertJsonPath('data.version', 4);

        $booking = $booking->fresh();
        $this->assertSame(CarbonImmutable::parse("{$newDate} 10:00", 'Asia/Jerusalem')->utc()->toIso8601String(), $booking->stage_due_at->toIso8601String());
        $this->assertDatabaseHas('application_logs', ['booking_id' => $booking->id, 'action_type_id' => 104, 'status_id' => 102]);
        $this->assertSame(0, SyncFile::where('kind', SyncFile::KIND_BOOKING_VALUES)->count(), 'nothing is queued for the mobile app');

        // A past moment is refused, a requester field too.
        $this->as(self::INTERVIEW_COORDINATOR)->putJson($this->url($booking, '/values'), ['values' => [$dateField => CarbonImmutable::now('Asia/Jerusalem')->subDays(2)->toDateString()], 'version' => 4])->assertUnprocessable();
        $this->as(self::INTERVIEW_COORDINATOR)->putJson($this->url($booking, '/values'), ['values' => [(string) $this->field('notes')->id => 'x'], 'version' => 4])->assertUnprocessable();

        // Once interviewed the section is closed to edits.
        Booking::whereKey($booking->id)->update(['status_id' => 103]);
        $this->as(self::INTERVIEW_COORDINATOR)->putJson($this->url($booking, '/values'), ['values' => [$dateField => $newDate], 'version' => 4])->assertForbidden();
    }

    public function test_the_scheduler_moves_a_request_on_once_its_interview_moment_has_passed(): void
    {
        $booking = $this->scheduled();

        Artisan::call('workflow:advance');
        $this->assertSame(102, (int) $booking->fresh()->status_id, 'not yet');

        $this->travelTo($booking->stage_due_at->copy()->addMinute());
        Artisan::call('workflow:advance');

        $booking = $booking->fresh();
        $this->assertSame(103, (int) $booking->status_id);
        $this->assertSame('system', $booking->status_source);
        $this->assertSame(4, (int) $booking->version);
        $this->assertNull($booking->stage_due_at);

        $log = ApplicationLog::where('booking_id', $booking->id)->where('status_id', 103)->firstOrFail();
        $this->assertNull($log->user_id, 'nobody pressed anything');
        $this->assertSame('system', $log->source);
        $this->assertDatabaseHas('audit_log', ['action' => 'booking.status_change', 'booking_serial' => $booking->serial_number, 'actor_type' => 'system', 'reason' => 'מועד התחקיר חלף']);

        Artisan::call('workflow:advance');
        $this->assertSame(4, (int) $booking->fresh()->version, 'a second run changes nothing');

        // The timeline shows the system move and the detail the stage.
        $detail = $this->as(self::INTERVIEW_COORDINATOR)->getJson($this->url($booking))->assertOk()->json('data');
        $this->assertSame('interviewed', $detail['stage']);
        $this->assertSame([104], array_column($detail['actions'], 'status_id'));
        $this->assertNull(collect($detail['history'])->firstWhere('status_id', 103)['actor_name']);
        $this->assertSame('system', collect($detail['history'])->firstWhere('status_id', 103)['source']);
    }

    public function test_the_officer_closes_the_request_once_the_clearance_is_recorded_and_the_registry_may_do_it_alone(): void
    {
        $booking = $this->scheduled();
        Booking::whereKey($booking->id)->update(['status_id' => 103, 'stage_due_at' => null]);

        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 104, 'version' => 3])->assertOk();
        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 105, 'version' => 4])->assertForbidden();

        // The registry: off by default, then on with a fake that knows the candidate at "secret" (the request asks for "secret").
        Artisan::call('workflow:advance', ['--registry' => true]);
        $this->assertSame(104, (int) $booking->fresh()->status_id, 'auto close is off by default');

        config(['workflow.clearance.auto_close' => true]);
        $this->app->bind(ClearanceRegistry::class, fn () => new class implements ClearanceRegistry {
            public function levelCodeFor(string $personalId): ?string
            {
                return $personalId === '300000199' ? 'secret' : null;
            }
        });

        $topSecret = $this->request([], 'top_secret');
        Booking::whereKey($topSecret->id)->update(['status_id' => 104]);

        Artisan::call('workflow:advance', ['--registry' => true]);

        $this->assertSame(105, (int) $booking->fresh()->status_id, 'the requested level is held: closed');
        $this->assertSame(104, (int) $topSecret->fresh()->status_id, 'a higher request waits on');
        $this->assertDatabaseHas('audit_log', ['action' => 'booking.status_change', 'booking_serial' => $booking->serial_number, 'actor_type' => 'system', 'reason' => 'הסיווג נקלט בוותיקן']);

        // A registry that is down moves nothing.
        $this->app->bind(ClearanceRegistry::class, fn () => new class implements ClearanceRegistry {
            public function levelCodeFor(string $personalId): ?string
            {
                throw new \RuntimeException('down');
            }
        });
        Artisan::call('workflow:advance', ['--registry' => true]);
        $this->assertSame(104, (int) $topSecret->fresh()->status_id);

        // By hand, the officer closes it: terminal, no buttons, done for the requester's counters.
        $this->as(self::INFOSEC_OFFICER)->postJson(self::BASE . '/bookings/' . $topSecret->uuid . '/status', ['status_id' => 105, 'version' => 1])->assertOk();
        $this->assertSame('closed', $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings/' . $topSecret->uuid)->json('data.stage'));
        $this->assertSame([], $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/bookings/' . $topSecret->uuid)->json('data.actions'));
        $this->assertSame(2, $this->as(self::SECURITY_OFFICER)->getJson('/api/requester/counters')->json('data.done'));
    }

    //----------------------------- Back and refusal ----------------------------//

    public function test_the_officer_moves_a_request_back_with_a_reason_and_the_interview_details_are_deleted(): void
    {
        $booking = $this->scheduled();
        $interviewFieldIds = array_map('intval', array_keys($this->interviewValues()));

        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 101, 'version' => 3, 'comment' => 'המועמד לא הגיע'])->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->postJson($this->url($booking, '/status'), ['status_id' => 101, 'version' => 3])
            ->assertUnprocessable()->assertJsonPath('message', 'יש לצרף הערה לביצוע פעולה זו');

        $this->as(self::INFOSEC_OFFICER)->postJson($this->url($booking, '/status'), ['status_id' => 101, 'version' => 3, 'comment' => 'המועמד לא הגיע'])
            ->assertOk()->assertJsonPath('data.status_id', 101);

        $booking = $booking->fresh();
        $this->assertSame(0, BookingValue::where('booking_id', $booking->id)->whereIn('form_field_id', $interviewFieldIds)->count(), 'the place, date and time are gone');
        $this->assertNull($booking->stage_due_at);
        $this->assertNotNull($booking->handler_user_id, 'the handler stays');
        $this->assertDatabaseHas('application_logs', ['booking_id' => $booking->id, 'action_type_id' => 103, 'status_id' => 101]);

        $audit = AuditLog::where('action', 'booking.status_change')->where('booking_serial', $booking->serial_number)->where('reason', 'המועמד לא הגיע')->firstOrFail();
        $this->assertSame('בניין 3 חדר 12', $audit->before['values']['מקום התחקיר']);
        $this->assertSame('המועמד לא הגיע', collect($this->as(self::INFOSEC_OFFICER)->getJson($this->url($booking))->json('data.comments'))->pluck('comment')->first());

        // Back at the start the progress bar restarts and the empty section is ready to be filled again.
        $detail = $this->as(self::INFOSEC_OFFICER)->getJson($this->url($booking))->json('data');
        $this->assertSame(['done', 'current', 'upcoming', 'upcoming', 'upcoming', 'upcoming'], array_column($detail['progress'], 'state'));
        $this->assertSame([null, null, null], array_column($detail['handler_fields'], 'value'));
    }

    public function test_the_officer_refuses_a_request_with_a_reason_and_the_super_admin_holds_every_move(): void
    {
        $booking = $this->request();

        $this->as(self::INTERVIEW_COORDINATOR)->postJson($this->url($booking, '/status'), ['status_id' => 4, 'version' => 1, 'comment' => 'לא עומד בתנאים'])->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->postJson($this->url($booking, '/status'), ['status_id' => 4, 'version' => 1, 'comment' => 'לא עומד בתנאים'])->assertOk();

        $detail = $this->as(self::INFOSEC_OFFICER)->getJson($this->url($booking))->json('data');
        $this->assertSame('closed', $detail['stage']);
        $this->assertSame('rejected', end($detail['progress'])['state']);
        $this->assertSame(4, end($detail['progress'])['status_id']);
        $this->assertDatabaseHas('application_logs', ['booking_id' => $booking->id, 'action_type_id' => 5, 'status_id' => 4]);

        // The super admin, as the type's admin, assigns and runs the route by hand.
        $other = $this->request();
        $this->assign($other, self::SUPER_ADMIN, self::INTERVIEW_COORDINATOR, 1)->assertOk();
        $this->as(self::SUPER_ADMIN)->postJson($this->url($other, '/status'), ['status_id' => 102, 'version' => 2, 'values' => $this->interviewValues()])->assertOk();
        $this->as(self::SUPER_ADMIN)->postJson($this->url($other, '/status'), ['status_id' => 101, 'version' => 3, 'comment' => 'שינוי'])->assertOk();
        $this->assertSame([102, 4], array_column($this->as(self::SUPER_ADMIN)->getJson($this->url($other))->json('data.actions'), 'status_id'));
    }

    //----------------------------- Access --------------------------------------//

    public function test_who_sees_a_clearance_request(): void
    {
        $booking = $this->request();
        $otherRequester = $this->createPerson('1000023', \App\Enums\RoleEnum::DEPARTMENT_SECURITY_OFFICER);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // The requester reads their own; another קב"ט, a plain user and an ICT handler of the unit do not.
        $this->as(self::SECURITY_OFFICER)->getJson('/api/bookings/' . $booking->uuid)->assertOk()->assertJsonPath('data.status.id', 101);
        $this->as('1000023')->getJson('/api/bookings/' . $booking->uuid)->assertNotFound();
        $this->as(self::PLAIN_USER)->getJson('/api/bookings/' . $booking->uuid)->assertNotFound();

        $ict = $this->createPerson('1000024', \App\Enums\RoleEnum::ICT_HANDLER);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->as('1000024')->getJson('/api/bookings/' . $booking->uuid)->assertNotFound();
        $this->as('1000024')->getJson($this->url($booking))->assertNotFound();

        // Both route roles see it in the management list and detail; the queue of an officer of another infosec type stays empty.
        foreach ([self::INFOSEC_OFFICER, self::INTERVIEW_COORDINATOR, self::SUPER_ADMIN] as $person) {
            $this->assertContains($booking->serial_number, $this->as($person)->getJson(self::BASE . '/bookings?types=14')->assertOk()->json('data.*.serial_number'), $person);
            $this->as($person)->getJson($this->url($booking))->assertOk();
        }

        $this->as(self::ADMIN)->getJson($this->url($booking))->assertNotFound();
        $this->as(self::APPROVER)->getJson($this->url($booking))->assertNotFound();

        $mediaOfficer = $this->createPerson('1000025', \App\Enums\RoleEnum::INFOSEC_OFFICER);
        $mediaOfficer->revokePermissionTo('infosec_officer_14');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame([], $this->as('1000025')->getJson(self::BASE . '/bookings')->assertOk()->json('data'));

        // What each one is told about themself.
        $officer = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertSame([14], $officer['managed_booking_types']);
        $this->assertSame([14], $officer['dashboard_booking_types']);
        $this->assertTrue($officer['capabilities']['dashboard.view']);
        $this->assertFalse($officer['is_handler_only']);

        $coordinator = $this->as(self::INTERVIEW_COORDINATOR)->getJson(self::BASE . '/me')->assertOk()->json('data');
        $this->assertSame([14], $coordinator['managed_booking_types']);
        $this->assertSame([], $coordinator['dashboard_booking_types']);
        $this->assertTrue($coordinator['is_handler_only']);

        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/domain-dashboard?types=14')->assertOk()->assertJsonPath('data.booking_type_ids', [14]);
        $this->as(self::INTERVIEW_COORDINATOR)->getJson(self::BASE . '/domain-dashboard?types=14')->assertForbidden();
        $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/settings/1/14')->assertOk()->assertJsonPath('data.can_edit_global', false);
        $this->as(self::INTERVIEW_COORDINATOR)->getJson(self::BASE . '/settings/1/14')->assertForbidden();

        // The catalog tells the screens about the route.
        $catalog = $this->as(self::INFOSEC_OFFICER)->getJson(self::BASE . '/catalog')->assertOk()->json('data');
        $this->assertSame([101, 102, 103, 104, 105], array_column($catalog['routes']['14']['steps'], 'status_id'));
        $this->assertSame('department_security_officer', $catalog['routes']['14']['requester_role']['name']);
        $this->assertSame([4, 101, 102, 103, 104, 105], $catalog['booking_type_statuses']['14']);
    }

    //----------------------------- The mobile side -----------------------------//

    public function test_nothing_of_the_route_reaches_the_mobile_side(): void
    {
        Storage::fake('minio');
        $booking = $this->scheduled();

        // The status file: a pending car decision goes, the clearance request (even flagged) does not.
        Booking::whereKey($booking->id)->update(['is_status_exported' => false]);
        $car = Booking::create(['serial_number' => 800000399, 'unit_id' => 1, 'booking_type_id' => 1, 'user_id' => $this->user(self::PLAIN_USER)->id, 'status_id' => 3, 'status_source' => 'internal', 'status_changed_at' => now(), 'is_status_exported' => false]);

        $built = app(BookingStatusSnapshotService::class)->run();
        $this->assertTrue($built['written']);
        $this->assertSame(1, $built['bookings']);
        $this->assertStringNotContainsString((string) $booking->serial_number, Storage::disk('minio')->get($built['bucket_key']));
        $this->assertStringContainsString('800000399', Storage::disk('minio')->get($built['bucket_key']));

        // The users file: the people of the route hold web-only permissions only, so they are not in it; a grant of a web-only role does not
        // even flag them for it.
        User::query()->update(['is_exported_role' => false]);
        $rows = app(UsersExportService::class)->payload();
        $this->assertNotContains($this->user(self::INFOSEC_OFFICER)->personal_id, array_column($rows, 'personal_id'));

        foreach ($rows as $row) {
            foreach ($row['booking_type_roles'] as $roleRow) {
                $this->assertLessThan(7, $roleRow['role_id'], 'no web-only role travels');
                $this->assertSame([], array_values(array_intersect($roleRow['booking_type_ids'], range(14, 25))), 'no web-only type travels');
            }
        }

        // The config channel: a field added to the clearance form queues nothing.
        $this->as(self::SUPER_ADMIN)->postJson('/api/bookings-super-admin-management/form-fields/1/14', ['field_display_name' => 'שדה נוסף', 'field_type_id' => 2, 'is_required' => false])->assertCreated();
        $this->assertSame(0, SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->count());

        // The sync-status screen does not list it.
        $this->assertNotContains($booking->uuid, $this->as(self::SUPER_ADMIN)->getJson('/api/bookings-super-admin-management/application-sync/bookings?per_page=100')->assertOk()->json('data.*.uuid'));
    }
}

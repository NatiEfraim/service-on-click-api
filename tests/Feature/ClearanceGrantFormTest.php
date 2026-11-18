<?php

namespace Tests\Feature;

use App\Domain\Workflow\Routes\ClearanceGrantFields;
use App\Enums\FormSectionEnum;
use App\Enums\UnitEnum;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * The clearance grant form (type 14): who may open and submit it, the requester section the portal shows, the server-side rules on the
 * values, the person lookup that fills the candidate's details, and what a submission leaves behind.
 */
class ClearanceGrantFormTest extends ManagementTestCase
{
    private const TYPE = 14;

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

    /** The requester fields of the form keyed by name. @return array<string, FormField> */
    private function fields(): array
    {
        return FormField::query()
            ->where('booking_type_id', self::TYPE)
            ->where('unit_id', UnitEnum::H->value)
            ->where('is_active', true)
            ->get()
            ->keyBy('field_name')
            ->all();
    }

    private function optionId(string $fieldName, string $code): int
    {
        return (int) OptionField::query()->where('form_field_id', $this->fields()[$fieldName]->id)->where('code', $code)->value('id');
    }

    /** A complete, valid values map by field id, with overrides by field NAME. @param array<string, mixed> $overrides */
    private function validValues(array $overrides = []): array
    {
        $byName = [
            'personal_id' => '300000199',
            'personal_number' => '7654321',
            'first_name' => 'ישראל',
            'last_name' => 'ישראלי',
            'phone' => '0521234567',
            'department' => 'מחלקת מבצעים',
            'branch' => 'ענף תכנון',
            'rank' => 'סרן',
            'service_type' => $this->optionId('service_type', 'career'),
            'commander_name' => 'דני דין',
            'commander_phone' => '0529876543',
            'requested_level' => $this->optionId('requested_level', 'secret'),
            'preferred_interview_location' => $this->optionId('preferred_interview_location', 'center'),
            'candidate_role' => 'קצין מבצעים',
            'role_characteristics' => 'חשיפה למידע מסווג',
            'notes' => 'אין הערות',
        ];

        foreach ($overrides as $name => $value) {
            $byName[$name] = $value;
        }

        $fields = $this->fields();
        $values = [];

        foreach ($byName as $name => $value) {
            if ($value === null) {
                continue;
            }

            $values[$fields[$name]->id] = $value;
        }

        return $values;
    }

    public function test_the_portal_form_is_the_requester_section_in_order_with_the_lookup_and_the_rules(): void
    {
        $fields = $this->as(self::SECURITY_OFFICER)->getJson('/api/requester/form/' . self::TYPE)->assertOk()->json('data');

        $this->assertSame([
            'personal_id', 'personal_number', 'first_name', 'last_name', 'phone', 'department', 'branch', 'rank', 'service_type',
            'commander_name', 'commander_phone', 'requested_level', 'preferred_interview_location', 'candidate_role', 'role_characteristics', 'notes',
        ], array_column($fields, 'field_name'), 'the 16 requester fields, by position, and no handler field');

        $byName = collect($fields)->keyBy('field_name');

        $this->assertSame('person', $byName['personal_id']['lookup_source']);
        $this->assertNull($byName['personal_id']['autofill_key']);
        $this->assertSame('personal_number', $byName['personal_number']['autofill_key']);
        $this->assertSame('rank', $byName['rank']['autofill_key']);
        $this->assertSame('requester', $byName['notes']['section']);
        $this->assertSame(4, $byName['notes']['col_span']);
        $this->assertSame(2, $byName['candidate_role']['col_span']);
        $this->assertFalse($byName['branch']['is_required']);
        $this->assertIsArray($byName['personal_id']['validations'], 'validations is an object, not a JSON string');
        $this->assertSame('תעודת זהות חייבת להכיל 9 ספרות בדיוק', $byName['personal_id']['validations']['regex:/^\d{9}$/']);
        $this->assertSame(['restricted', 'secret', 'top_secret'], array_column($byName['requested_level']['options'], 'code'));

        // The handler section exists on the type but is not the requester's to see or fill.
        $this->assertSame(3, FormField::query()->where('booking_type_id', self::TYPE)->section(FormSectionEnum::HANDLER)->count());
    }

    public function test_only_the_security_officer_and_the_super_admin_submit_the_clearance_grant(): void
    {
        Storage::fake('minio');

        foreach ([self::PLAIN_USER, self::ADMIN, self::APPROVER] as $notARequester) {
            $this->as($notARequester)->getJson('/api/requester/form/' . self::TYPE)->assertForbidden();
            $this->as($notARequester)->postJson('/api/bookings/' . self::TYPE, ['values' => $this->validValues()])->assertForbidden();
        }

        $this->assertArrayNotHasKey(self::TYPE, $this->catalogTypeIds(self::PLAIN_USER));
        $this->assertArrayHasKey(self::TYPE, $this->catalogTypeIds(self::SECURITY_OFFICER));

        $this->as(self::SECURITY_OFFICER)->postJson('/api/bookings/' . self::TYPE, ['values' => $this->validValues()])->assertCreated();
        $this->as(self::SUPER_ADMIN)->postJson('/api/bookings/' . self::TYPE, ['values' => $this->validValues()])->assertCreated();
    }

    /** @return array<int, true> */
    private function catalogTypeIds(string $personalNumber): array
    {
        $catalog = $this->as($personalNumber)->getJson('/api/requester/catalog')->assertOk()->json('data');
        $ids = [];

        foreach ($catalog['topics'] as $topic) {
            foreach ($topic['groups'] as $group) {
                foreach ($group['booking_types'] as $type) {
                    $ids[(int) $type['id']] = true;
                }
            }
        }

        foreach ($catalog['domains'] as $type) {
            $ids[(int) $type['id']] = true;
        }

        return $ids;
    }

    public function test_the_server_enforces_the_fields_rules(): void
    {
        $fields = $this->fields();
        $post = fn (array $values) => $this->as(self::SECURITY_OFFICER)->postJson('/api/bookings/' . self::TYPE, ['values' => $values]);

        // A missing required field.
        $post($this->validValues(['first_name' => null]))->assertUnprocessable()->assertJsonValidationErrors(['values.' . $fields['first_name']->id]);
        // An 8-digit ID number: the field's own message, under the field's key.
        $this->assertSame(
            ['תעודת זהות חייבת להכיל 9 ספרות בדיוק'],
            $post($this->validValues(['personal_id' => '30000019']))->assertUnprocessable()->json('errors')['values.' . $fields['personal_id']->id]
        );
        // A landline in a mobile field.
        $post($this->validValues(['phone' => '039876543']))->assertUnprocessable()->assertJsonValidationErrors(['values.' . $fields['phone']->id]);
        // An option of another field.
        $post($this->validValues(['requested_level' => $this->optionId('service_type', 'career')]))->assertUnprocessable()->assertJsonValidationErrors(['values.' . $fields['requested_level']->id]);
        // A handler field cannot be pre-set by the requester.
        $this->assertSame(
            ['שדה זה אינו ניתן למילוי בשלב ההגשה'],
            $post($this->validValues() + [$fields[ClearanceGrantFields::INTERVIEW_LOCATION]->id => 'בניין 3'])->assertUnprocessable()->json('errors')['values.' . $fields[ClearanceGrantFields::INTERVIEW_LOCATION]->id]
        );

        $this->assertSame(0, Booking::where('booking_type_id', self::TYPE)->where('user_id', User::where('personal_number', self::SECURITY_OFFICER)->value('id'))->count(), 'a refused submission creates nothing');
    }

    public function test_a_submission_starts_the_route_with_its_timeline_and_leaves_the_mobile_side_alone(): void
    {
        $uuid = $this->as(self::SECURITY_OFFICER)->postJson('/api/bookings/' . self::TYPE, ['values' => $this->validValues(), 'comment' => 'בקשה דחופה'])
            ->assertCreated()
            ->assertJsonPath('data.status_id', 101)
            ->json('data.uuid');

        $booking = Booking::where('uuid', $uuid)->firstOrFail();
        $this->assertSame('web', $booking->status_source);
        $this->assertTrue($booking->is_status_exported, 'nothing waits to be sent to the mobile app');
        $this->assertNull($booking->handler_user_id);
        $this->assertNull($booking->stage_due_at);

        $log = ApplicationLog::where('booking_id', $booking->id)->get();
        $this->assertCount(1, $log, 'the creation is the first row of the timeline');
        $this->assertSame([1, 101, 'web'], [(int) $log[0]->action_type_id, (int) $log[0]->status_id, $log[0]->source]);
        $this->assertDatabaseHas('audit_log', ['action' => 'booking.create', 'booking_serial' => $booking->serial_number, 'result' => 'success']);

        // The requester's view: the progress bar, no handler yet, the values of the requester section, nothing to cancel.
        $shown = $this->as(self::SECURITY_OFFICER)->getJson('/api/bookings/' . $uuid)->assertOk()->json('data');
        $this->assertSame(['submitted', 'interview_assignment', 'interview_scheduled', 'interviewed', 'awaiting_clearance', 'clearance_granted'], array_column($shown['progress'], 'key'));
        $this->assertSame(['done', 'current', 'upcoming', 'upcoming', 'upcoming', 'upcoming'], array_column($shown['progress'], 'state'));
        $this->assertArrayNotHasKey('handler', $shown);
        $this->assertFalse($shown['is_cancelable']);
        $this->assertSame(['requester'], array_values(array_unique(array_column($shown['booking_values'], 'section'))));
        $this->assertSame(16, count($shown['booking_values']));
        $this->assertSame('בקשה דחופה', $shown['comments'][0]['comment']);
    }

    public function test_the_person_lookup_fills_the_candidates_details_and_the_server_does_not_trust_the_client_for_them(): void
    {
        // Someone the system knows (the mobile import and the logins fill the users table).
        User::factory()->create(['personal_id' => '300000777', 'personal_number' => '7777777', 'full_name' => 'משה אהרון כהן', 'unit_id' => UnitEnum::H->value]);

        $lookup = $this->as(self::SECURITY_OFFICER)
            ->getJson('/api/requester/person-lookup?booking_type_id=' . self::TYPE . '&personal_id=300000777')
            ->assertOk()
            ->json('data');

        $this->assertTrue($lookup['found']);
        $this->assertSame(['personal_number' => '7777777', 'first_name' => 'משה אהרון', 'last_name' => 'כהן', 'rank' => null], $lookup['person']);
        $this->assertDatabaseHas('audit_log', ['action' => 'person.lookup', 'entity_id' => '300000777', 'booking_type_id' => self::TYPE]);

        $this->as(self::SECURITY_OFFICER)->getJson('/api/requester/person-lookup?booking_type_id=' . self::TYPE . '&personal_id=300000001')
            ->assertOk()->assertJsonPath('data.found', false);
        $this->as(self::SECURITY_OFFICER)->getJson('/api/requester/person-lookup?booking_type_id=' . self::TYPE . '&personal_id=12345')->assertUnprocessable();
        $this->as(self::PLAIN_USER)->getJson('/api/requester/person-lookup?booking_type_id=' . self::TYPE . '&personal_id=300000777')->assertForbidden();
        $this->as(self::PLAIN_USER)->getJson('/api/requester/person-lookup?booking_type_id=22&personal_id=300000777')->assertForbidden();

        // On submission the looked-up details win over what the client typed; a person who is not found keeps the typed ones.
        $fields = $this->fields();
        $found = $this->as(self::SECURITY_OFFICER)->postJson('/api/bookings/' . self::TYPE, ['values' => $this->validValues(['personal_id' => '300000777', 'first_name' => 'אחר', 'last_name' => 'לגמרי', 'personal_number' => '1111111', 'rank' => 'רס"ן'])])
            ->assertCreated()->json('data.uuid');
        $stored = fn (string $uuid, string $name) => BookingValue::where('booking_id', Booking::where('uuid', $uuid)->value('id'))->where('form_field_id', $fields[$name]->id)->value('value');

        $this->assertSame('משה אהרון', $stored($found, 'first_name'));
        $this->assertSame('כהן', $stored($found, 'last_name'));
        $this->assertSame('7777777', $stored($found, 'personal_number'));
        $this->assertSame('רס"ן', $stored($found, 'rank'), 'no source holds the rank: the typed one stays');

        $notFound = $this->as(self::SECURITY_OFFICER)->postJson('/api/bookings/' . self::TYPE, ['values' => $this->validValues(['personal_id' => '300000199', 'first_name' => 'דנה'])])
            ->assertCreated()->json('data.uuid');
        $this->assertSame('דנה', $stored($notFound, 'first_name'));
    }

    /** The option editor: a field whose choices all carry codes (the clearance levels) compares codes, so a new choice needs one; codes travel through the request. */
    public function test_the_option_editor_keeps_the_codes_of_the_clearance_levels(): void
    {
        $fieldId = $this->fields()['requested_level']->id;
        $url = '/api/bookings-super-admin-management/update-option/' . $fieldId;

        $this->as(self::SUPER_ADMIN)->putJson($url, ['option_name' => 'שמור ביותר', 'has_category' => 0])
            ->assertUnprocessable()->assertJsonPath('message', 'יש להגדיר קוד לאפשרות בשדה זה');
        $this->as(self::SUPER_ADMIN)->putJson($url, ['option_name' => 'שמור ביותר', 'has_category' => 0, 'code' => 'סודי'])
            ->assertUnprocessable();

        $optionId = (int) $this->as(self::SUPER_ADMIN)->putJson($url, ['option_name' => 'שמור ביותר', 'has_category' => 0, 'code' => 'ultra_secret'])
            ->assertOk()->assertJsonPath('data.code', 'ultra_secret')->json('data.option_id');
        $this->assertSame('ultra_secret', OptionField::query()->whereKey($optionId)->value('code'));

        // Renaming without sending a code keeps it; the requester form and the settings carry it.
        $this->as(self::SUPER_ADMIN)->putJson($url, ['option_id' => $optionId, 'option_name' => 'שמור ביותר מאוד', 'has_category' => 0])->assertOk();
        $this->assertSame('ultra_secret', OptionField::query()->whereKey($optionId)->value('code'));
        $this->assertSame(['restricted', 'secret', 'top_secret', 'ultra_secret'], array_column(collect($this->as(self::SECURITY_OFFICER)->getJson('/api/requester/form/' . self::TYPE)->json('data'))->firstWhere('field_name', 'requested_level')['options'], 'code'));
        $this->assertContains('ultra_secret', array_column($this->as(self::SUPER_ADMIN)->getJson('/api/bookings-approver-management/settings/1/' . self::TYPE)->assertOk()->json('data.options'), 'code'));
    }
}

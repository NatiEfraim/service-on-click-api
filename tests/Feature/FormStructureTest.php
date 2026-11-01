<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingFilter;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\SyncFile;
use App\Models\SystemReferenceHasFormField;
use App\Models\Unit;
use App\Models\User;

/**
 * The form of a booking type: a field is added, renamed, changed, moved and removed by the super admin, the mobile app is told, and what a booking
 * holds is never lost - a field or an option that has values is archived, not deleted.
 */
class FormStructureTest extends ImportJsonTestCase
{
    private const SUPER = '/api/bookings-super-admin-management';
    private const BASE = '/api/bookings-approver-management';

    /** In-unit food booking (type 11) of unit H: fields 6 name, 7 select, 8 amount, 9 date, 10 time. */
    private const FORM = '1/11';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** @param array<string, mixed> $data */
    private function addField(array $data = []): array
    {
        return $this->as(self::SUPER_ADMIN)
            ->postJson(self::SUPER . '/form-fields/' . self::FORM, [...['field_display_name' => 'הערות מיוחדות', 'field_type_id' => 2, 'is_required' => false], ...$data])
            ->assertCreated()
            ->json('data');
    }

    /** @return array<int, array<string, mixed>> */
    private function formFields(): array
    {
        return $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/settings/' . self::FORM)->assertOk()->json('data.form_fields');
    }

    /** @return array<int, array<string, mixed>> the changes queued for the mobile app, oldest first */
    private function queued(): array
    {
        return SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->orderBy('id')->get()
            ->flatMap(fn (SyncFile $file): array => json_decode($file->payload, true)['changes'])
            ->all();
    }

    private function bookingHolding(int $formFieldId, ?int $optionId = null): Booking
    {
        $booking = Booking::create([
            'serial_number' => 880000001,
            'unit_id' => 1,
            'booking_type_id' => 11,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => 1,
            'ext_status_id' => 1,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);

        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => $formFieldId, 'option_field_id' => $optionId, 'value' => json_encode('x')]);

        return $booking;
    }

    //----------------------------- Adding ---------------------------------------------//

    public function test_a_field_is_added_at_the_end_of_the_form_and_the_mobile_app_is_told(): void
    {
        $field = $this->addField(['field_display_name' => 'הערות מיוחדות']);

        $this->assertMatchesRegularExpression('/^int_[0-9a-f]{12}$/', $field['field_name']);
        $this->assertTrue($field['is_custom']);
        $this->assertSame(1, $field['version']);
        $this->assertSame([], $field['options']);

        $row = FormField::findOrFail($field['id']);
        $this->assertTrue($row->is_active);
        $this->assertTrue((bool) $row->is_input, 'a typed value, like the seeded text fields');
        $this->assertSame(1, $row->payload_section_id);
        $this->assertNull($row->field_validations, 'not required - no rule');
        $this->assertNotEmpty($row->uuid);

        // At the end: after every field the form had.
        $fields = $this->formFields();
        $this->assertSame($field['id'], end($fields)['id']);
        $this->assertSame(1, end($fields)['position']);
        $this->assertTrue(end($fields)['can_remove']);
        $this->assertTrue(end($fields)['can_change_type']);

        $this->assertDatabaseHas('audit_log', ['action' => 'config.form_field.create', 'entity_id' => $field['field_name'], 'result' => 'success']);

        $change = $this->queued()[0];
        $this->assertSame(['form_field', 'create', 1, 11, $field['field_name']], [$change['entity'], $change['op'], $change['unit_id'], $change['booking_type_id'], $change['field_key']]);
        $this->assertSame('הערות מיוחדות', $change['data']['field_display_name']);
        $this->assertSame(2, $change['data']['field_type_id']);
        $this->assertSame(1, $change['data']['position']);
        $this->assertArrayNotHasKey('id', $change, 'the id is this system\'s');

        // A second field goes after the first.
        $second = $this->addField(['field_display_name' => 'עוד שדה']);
        $this->assertSame(2, FormField::findOrFail($second['id'])->position);
    }

    public function test_a_required_field_gets_a_required_rule_with_its_message(): void
    {
        $field = $this->addField(['field_display_name' => 'מספר אישי', 'is_required' => true]);

        $this->assertSame(['required' => 'יש לספק מספר אישי'], FormField::findOrFail($field['id'])->field_validations);
    }

    public function test_a_choice_is_added_with_its_options(): void
    {
        $field = $this->addField(['field_display_name' => 'העדפת ישיבה', 'field_type_id' => 5, 'options' => ['ליד החלון', ' במעבר ', '']]);

        $this->assertSame(['ליד החלון', 'במעבר'], $field['options']);
        $this->assertSame(2, OptionField::where('form_field_id', $field['id'])->count());

        $row = FormField::findOrFail($field['id']);
        $this->assertFalse((bool) $row->is_input);
        $this->assertSame(0, $row->payload_section_id);

        $this->assertSame(['ליד החלון', 'במעבר'], $this->queued()[0]['data']['options']);
    }

    public function test_a_field_that_is_not_valid_is_refused_with_the_reason_and_leaves_nothing(): void
    {
        $count = FormField::count();

        $cases = [
            'a choice without options' => [['field_type_id' => 5], 'לפחות אפשרות אחת'],
            'options twice' => [['field_type_id' => 5, 'options' => ['כן', 'כן']], 'אפשרויות כפולות'],
            // The name of a field the form has already.
            'a name that is taken' => [['field_display_name' => FormField::findOrFail(6)->field_display_name], 'כבר קיים שדה בשם זה'],
            'a name too short' => [['field_display_name' => 'א'], 'בין 2 ל-50'],
            'a password' => [['field_type_id' => 12], 'סוג השדה אינו נתמך'],
            'editable on a text' => [['editable' => true], 'תאריך ושעה'],
        ];

        foreach ($cases as [$data, $message]) {
            $this->as(self::SUPER_ADMIN)
                ->postJson(self::SUPER . '/form-fields/' . self::FORM, [...['field_display_name' => 'שדה חדש', 'field_type_id' => 2], ...$data])
                ->assertUnprocessable()
                ->assertJsonPath('message', fn (string $text): bool => str_contains($text, $message));
        }

        $this->assertSame($count, FormField::count(), 'nothing was saved');
        $this->assertSame(0, SyncFile::count(), 'and nothing was sent');
    }

    public function test_a_field_cannot_carry_the_name_of_a_unit(): void
    {
        Unit::findOrFail(2)->update(['internal_name' => 'נשרים']);

        $this->as(self::SUPER_ADMIN)
            ->postJson(self::SUPER . '/form-fields/' . self::FORM, ['field_display_name' => 'נשרים', 'field_type_id' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $text): bool => str_contains($text, 'שם פנימי'));

        $this->assertSame(0, FormField::where('field_name', 'like', 'int\_%')->count());
        $this->assertSame(0, AuditLog::where('action', 'config.form_field.create')->count());
    }

    public function test_only_the_super_admin_edits_the_form_and_only_of_a_form_that_exists(): void
    {
        $body = ['field_display_name' => 'שדה', 'field_type_id' => 2];

        foreach ([self::ADMIN, self::APPROVER, self::VICE_APPROVER, self::PLAIN_USER, self::NO_ROLE] as $person) {
            $this->as($person)->postJson(self::SUPER . '/form-fields/' . self::FORM, $body)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson(self::SUPER . '/form-fields/' . self::FORM, $body)->assertUnauthorized();

        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/form-fields/99/11', $body)->assertNotFound();
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/form-fields/1/99', $body)->assertNotFound();
        $this->assertSame(0, SyncFile::count());
    }

    //----------------------------- Changing -------------------------------------------//

    public function test_a_field_is_renamed_and_only_what_changed_is_told(): void
    {
        $field = $this->addField();

        $response = $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 1, 'field_display_name' => 'הערות כלליות'])->assertOk();

        $response->assertJsonPath('data.field_display_name', 'הערות כלליות')->assertJsonPath('data.version', 2);

        $change = $this->queued()[1];
        $this->assertSame(['form_field', 'update', $field['field_name']], [$change['entity'], $change['op'], $change['field_key']]);
        $this->assertSame(['field_display_name' => 'הערות כלליות'], $change['data']);

        $audit = AuditLog::where('action', 'config.form_field.update')->firstOrFail();
        $this->assertSame('הערות מיוחדות', $audit->before['field_display_name']);
        $this->assertSame('הערות כלליות', $audit->after['field_display_name']);
    }

    public function test_a_screen_that_is_out_of_date_gets_a_409_and_changes_nothing(): void
    {
        $field = $this->addField();

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 1, 'field_display_name' => 'ראשון'])->assertOk();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 1, 'field_display_name' => 'שני'])->assertStatus(409);

        $this->assertSame('ראשון', FormField::findOrFail($field['id'])->field_display_name);
    }

    public function test_changing_nothing_is_not_a_change(): void
    {
        $field = $this->addField();
        $queued = SyncFile::count();

        $this->as(self::SUPER_ADMIN)
            ->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 1, 'field_display_name' => $field['field_display_name'], 'is_required' => false])
            ->assertOk()
            ->assertJsonPath('data.version', 1);

        $this->assertSame($queued, SyncFile::count());
        $this->assertSame(0, AuditLog::where('action', 'config.form_field.update')->count());
    }

    public function test_a_field_of_the_mobile_app_can_be_renamed_and_made_optional_but_not_retyped_or_removed(): void
    {
        $seeded = FormField::findOrFail(6);
        $this->assertFalse($seeded->isCustom());

        // The seeded fields carry what the mobile app gave them (an editable flag on a text, a name that repeats): an unrelated change is fine.
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/6', ['version' => (int) $seeded->version, 'is_required' => false])
            ->assertOk()->assertJsonPath('data.is_required', false);

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/6', ['version' => (int) $seeded->fresh()->version, 'field_display_name' => 'נושא הישיבה'])
            ->assertOk();

        $this->assertSame($seeded->field_name, $this->queued()[1]['field_key'], 'the mobile app knows the field by its name');
        $this->assertSame(['field_display_name' => 'נושא הישיבה'], $this->queued()[1]['data']);

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/6', ['version' => (int) $seeded->fresh()->version, 'field_type_id' => 6])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $text): bool => str_contains($text, 'באפליקציה'));

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . '/form-fields/6')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $text): bool => str_contains($text, 'באפליקציה'));

        $this->assertTrue(FormField::findOrFail(6)->is_active);
    }

    public function test_the_type_of_a_field_made_here_changes_while_nobody_has_filled_it(): void
    {
        $field = $this->addField();

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 1, 'field_type_id' => 6])
            ->assertOk()->assertJsonPath('data.field_type_id', 6);

        // A text cannot become a choice (the options would have to appear).
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 2, 'field_type_id' => 5])->assertUnprocessable();

        // Once a booking holds a value, the type stays.
        $this->bookingHolding($field['id']);
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 2, 'field_type_id' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $text): bool => str_contains($text, 'ערכים ב-'));
    }

    public function test_the_editable_flag_is_for_a_date_or_a_time_and_is_told_too(): void
    {
        $text = $this->addField();
        $date = $this->addField(['field_display_name' => 'תאריך יעד', 'field_type_id' => 3]);

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$text['id']}", ['version' => 1, 'editable' => true])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$date['id']}/editable", ['version' => 1, 'editable' => true])->assertOk();

        $change = collect($this->queued())->last();
        $this->assertSame(['update', $date['field_name'], ['editable' => true]], [$change['op'], $change['field_key'], $change['data']]);
    }

    //----------------------------- Moving ---------------------------------------------//

    public function test_the_fields_are_put_in_a_new_order_and_the_mobile_app_is_told(): void
    {
        $first = $this->addField(['field_display_name' => 'ראשון חדש']);
        $second = $this->addField(['field_display_name' => 'שני חדש']);

        $ids = array_column(array_filter($this->formFields(), fn (array $field): bool => $field['parent_id'] === null), 'id');
        $reversed = array_reverse($ids);

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/' . self::FORM . '/order', ['ids' => $reversed])
            ->assertOk()->assertJsonPath('data.saved', true);

        $now = array_column(array_filter($this->formFields(), fn (array $field): bool => $field['parent_id'] === null), 'id');
        $this->assertSame($reversed, $now);
        $this->assertSame(0, FormField::findOrFail($reversed[0])->position);
        $this->assertSame($second['id'], $reversed[0]);
        $this->assertSame($first['id'], $reversed[1]);

        $change = collect($this->queued())->last();
        $this->assertSame(['form_field', 'reorder'], [$change['entity'], $change['op']]);
        $this->assertSame($second['field_name'], $change['order'][0]);
        $this->assertCount(count($ids), $change['order']);

        // The same order again is nothing to send.
        $queued = SyncFile::count();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/' . self::FORM . '/order', ['ids' => $reversed])->assertOk();
        $this->assertSame($queued, SyncFile::count());
    }

    public function test_a_list_that_is_not_the_form_as_it_is_now_is_refused(): void
    {
        $this->addField();
        $ids = array_column(array_filter($this->formFields(), fn (array $field): bool => $field['parent_id'] === null), 'id');

        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/' . self::FORM . '/order', ['ids' => array_slice($ids, 1)])->assertStatus(409);
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/' . self::FORM . '/order', ['ids' => [...$ids, 999999]])->assertStatus(409);
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/' . self::FORM . '/order', ['ids' => [$ids[0], $ids[0]]])->assertUnprocessable();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . '/form-fields/' . self::FORM . '/order', [])->assertUnprocessable();
    }

    //----------------------------- Removing -------------------------------------------//

    public function test_a_field_nobody_filled_is_deleted_with_its_options(): void
    {
        $field = $this->addField(['field_display_name' => 'בחירה זמנית', 'field_type_id' => 5, 'options' => ['א1', 'ב2']]);

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . "/form-fields/{$field['id']}")
            ->assertOk()->assertJsonPath('data.mode', 'delete')->assertJsonPath('data.used_by', 0);

        $this->assertNull(FormField::find($field['id']));
        $this->assertSame(0, OptionField::where('form_field_id', $field['id'])->count());

        $change = collect($this->queued())->last();
        $this->assertSame(['delete', $field['field_name']], [$change['op'], $change['field_key']]);
        $this->assertDatabaseHas('audit_log', ['action' => 'config.form_field.delete', 'entity_id' => $field['field_name']]);
    }

    public function test_a_field_with_values_is_archived_and_the_bookings_keep_their_values(): void
    {
        $field = $this->addField();
        $booking = $this->bookingHolding($field['id']);

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . "/form-fields/{$field['id']}")
            ->assertOk()->assertJsonPath('data.mode', 'archive')->assertJsonPath('data.used_by', 1);

        $row = FormField::findOrFail($field['id']);
        $this->assertFalse($row->is_active);
        $this->assertSame(2, (int) $row->version);
        $this->assertSame(1, BookingValue::where('booking_id', $booking->id)->where('form_field_id', $field['id'])->count(), 'the value stays');

        // Out of the form, out of the settings - and still on the booking.
        $this->assertNotContains($field['id'], array_column($this->formFields(), 'id'));

        $detail = $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/bookings/880000001')->assertOk();
        $this->assertContains($field['id'], array_column($detail->json('data.booking_values'), 'form_field_id'));

        $this->assertSame('archive', collect($this->queued())->last()['op']);
        $this->assertDatabaseHas('audit_log', ['action' => 'config.form_field.archive', 'entity_id' => $field['field_name']]);

        // An archived field is not removed again, nor changed.
        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . "/form-fields/{$field['id']}")->assertNotFound();
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/form-fields/{$field['id']}", ['version' => 2, 'field_display_name' => 'שם חדש'])->assertNotFound();
    }

    public function test_a_field_a_setting_or_a_filter_depends_on_stays(): void
    {
        $linked = $this->addField(['field_display_name' => 'מקושר להגדרה']);
        $filtered = $this->addField(['field_display_name' => 'משמש כמסנן']);

        SystemReferenceHasFormField::create(['system_reference_id' => \App\Models\SystemReference::query()->value('id'), 'form_field_id' => $linked['id']]);
        BookingFilter::create(['unit_id' => 1, 'booking_type_id' => 11, 'form_field_id' => $filtered['id'], 'operator' => '=']);

        foreach ([$linked, $filtered] as $field) {
            $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . "/form-fields/{$field['id']}")
                ->assertStatus(409)
                ->assertJsonPath('message', fn (string $text): bool => str_contains($text, 'מקושר'));
        }

        $this->assertSame(2, FormField::whereIn('id', [$linked['id'], $filtered['id']])->where('is_active', true)->count());
    }

    public function test_the_version_may_be_sent_with_the_removal(): void
    {
        $field = $this->addField();

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . "/form-fields/{$field['id']}?version=7")->assertStatus(409);
        $this->assertNotNull(FormField::find($field['id']));

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . "/form-fields/{$field['id']}?version=1")->assertOk();
    }

    //----------------------------- Values that come back ------------------------------//

    public function test_the_values_of_a_field_made_here_are_found_by_its_name_when_the_mobile_app_sends_them(): void
    {
        $text = $this->addField(['field_display_name' => 'הערה חופשית']);
        $choice = $this->addField(['field_display_name' => 'העדפת ישיבה', 'field_type_id' => 5, 'options' => ['ליד החלון', 'במעבר']]);

        // The mobile app made the same fields with ids of its own (9001, 9002) and sends them with the name it got in the system_config file.
        $booking = $this->foodBooking(6001);
        $booking['booking_values'][] = [...$this->node(9001, null, 2, 'טקסט חופשי'), 'field_name' => $text['field_name']];
        $booking['booking_values'][] = [
            ...$this->node(9002, null, 5, null, [...$this->option(9101, 9002), 'display_name' => 'במעבר']),
            'field_name' => $choice['field_name'],
        ];
        $this->putBookingFile([$booking]);

        $result = app(\App\Services\ImportBookingService::class)->importFromBucket(sync: true);

        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['files'][0]['stats']['values_skipped']);

        $bookingId = Booking::where('serial_number', 6001)->value('id');

        $textRow = BookingValue::where('booking_id', $bookingId)->where('form_field_id', $text['id'])->firstOrFail();
        $this->assertSame('טקסט חופשי', json_decode($textRow->getRawOriginal('value')));

        $optionId = OptionField::where('form_field_id', $choice['id'])->where('name', 'במעבר')->value('id');
        $this->assertSame(1, BookingValue::where('booking_id', $bookingId)->where('form_field_id', $choice['id'])->where('option_field_id', $optionId)->count());
    }

    public function test_a_node_with_a_mobile_id_and_no_name_still_means_what_it_always_meant(): void
    {
        $this->addField(['field_display_name' => 'הערה חופשית']);

        // 9001 is nothing this system knows, and there is no name to find it by: skipped and counted, as before.
        $booking = $this->foodBooking(6002);
        $booking['booking_values'][] = $this->node(9001, null, 2, 'לא ידוע');
        $this->putBookingFile([$booking]);

        $result = app(\App\Services\ImportBookingService::class)->importFromBucket(sync: true);

        $this->assertSame(1, $result['files'][0]['stats']['values_skipped']);
        $this->assertSame(5, BookingValue::where('booking_id', Booking::where('serial_number', 6002)->value('id'))->count(), 'the five values of the food booking, nothing else');
    }

    //----------------------------- Options that bookings chose ------------------------//

    public function test_an_option_a_booking_chose_is_archived_not_deleted_and_its_values_stay(): void
    {
        // Option 3 of the select (field 7) of the in-unit food booking; a booking chose it.
        $booking = $this->bookingHolding(7, 3);
        $option = OptionField::findOrFail(3);
        $this->assertNull($option->option_field_id, 'a top option');

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . '/remove-option/7', ['option_id' => 3])->assertOk();

        $this->assertFalse((bool) OptionField::findOrFail(3)->is_active, 'archived, not deleted');
        $this->assertSame(1, BookingValue::where('booking_id', $booking->id)->where('option_field_id', 3)->count(), 'the booking keeps what it chose');

        // Out of the lists the settings screen and the option editor show ...
        $this->assertNotContains(3, array_column($this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/settings/' . self::FORM)->json('data.options'), 'id'));
        $this->assertNotContains(3, array_column($this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/get-select-options/7')->assertOk()->json('data') ?? [], 'option_id'));

        // ... and still on the booking.
        $detail = $this->as(self::SUPER_ADMIN)->getJson(self::BASE . '/bookings/880000001')->assertOk();
        $this->assertContains(7, array_column($detail->json('data.booking_values'), 'form_field_id'));

        $change = collect($this->queued())->last();
        $this->assertSame(['option_field', 'archive', [$option->name]], [$change['entity'], $change['op'], $change['option_path']]);
        $this->assertDatabaseHas('audit_log', ['action' => 'config.options.remove', 'entity_id' => '7']);
    }

    public function test_an_option_nobody_chose_is_still_deleted_and_the_mobile_app_is_told(): void
    {
        $optionId = (int) $this->as(self::SUPER_ADMIN)
            ->putJson(self::SUPER . '/update-option/7', ['option_name' => 'אפשרות זמנית', 'has_category' => 0])
            ->assertOk()->json('data.option_id');

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . '/remove-option/7', ['option_id' => $optionId])->assertOk();

        $this->assertNull(OptionField::find($optionId));
        $this->assertSame('delete', collect($this->queued())->last()['op']);
    }
}

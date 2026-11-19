<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BookingTypeHasStatement;
use App\Models\Department;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\SyncFile;
use App\Models\SystemReference;
use App\Models\Unit;
use App\Services\BookingStatusExportService;
use Illuminate\Support\Facades\Storage;

/**
 * The changes of the configuration on their way to the mobile app: queued with the change itself, written to the bucket in the order they were
 * made, stopped by a file that cannot go, and never carrying the name of a unit or a department.
 */
class ConfigChannelTest extends ImportJsonTestCase
{
    private const SUPER = '/api/bookings-super-admin-management';
    private const BASE = '/api/bookings-approver-management';
    private const FOLDER = 'export-json/system-config';

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    private function statement(string $text, int $unit = 1, int $type = 1): void
    {
        $this->as(self::SUPER_ADMIN)->putJson(self::SUPER . "/update-statement/{$unit}/{$type}", ['statement' => $text, 'is_active' => true])->assertOk();
    }

    /** @return array<int, array<string, mixed>> the changes of every queued config file, oldest file first */
    private function queuedChanges(): array
    {
        return SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->orderBy('id')->get()
            ->flatMap(fn (SyncFile $file): array => json_decode($file->payload, true)['changes'])
            ->all();
    }

    private function exportNow(): array
    {
        return app(BookingStatusExportService::class)->run();
    }

    //----------------------------- What is queued ------------------------------------//

    public function test_a_statement_change_is_queued_for_the_mobile_app_in_the_same_request(): void
    {
        $this->statement('הצהרה חדשה לבדיקה');

        $file = SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->firstOrFail();
        $this->assertSame(SyncFile::STATE_QUEUED, $file->state);
        $this->assertSame('outbound', $file->direction);
        $this->assertMatchesRegularExpression('/^SrvOnClick_r2b_system_config_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_0{6}' . str_pad((string) $file->id, 3, '0', STR_PAD_LEFT) . '\.json$/', $file->file_name);

        $change = json_decode($file->payload, true)['changes'][0];
        $this->assertSame('statement', $change['entity']);
        $this->assertSame('update', $change['op']);
        $this->assertSame(1, $change['unit_id']);
        $this->assertSame(1, $change['booking_type_id']);
        $this->assertSame('הצהרה חדשה לבדיקה', $change['data']['statement']);
        $this->assertTrue($change['data']['is_active']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $change['correlation_id']);
        $this->assertIsInt($change['changed_at']);

        // The same text again changes nothing, so nothing is sent.
        $this->statement('הצהרה חדשה לבדיקה');
        $this->assertSame(1, SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->count());
    }

    public function test_a_setting_is_told_with_its_whole_value_and_blocked_dates_with_the_new_list(): void
    {
        $referenceId = (int) SystemReference::where('name', 'contact_phone_car_booking')->where('unit_id', 1)->value('id');

        $this->as(self::APPROVER)
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$referenceId => ['contact_phone' => '0501234567']]])
            ->assertOk();

        $change = $this->queuedChanges()[0];
        $this->assertSame('system_reference', $change['entity']);
        $this->assertSame('update', $change['op']);
        $this->assertSame('contact_phone_car_booking', $change['key']);
        $this->assertSame('0501234567', $change['data']['contact_phone']);

        $datesId = (int) SystemReference::where('name', 'restriction_dates_car_booking')->where('unit_id', 1)->value('id');
        $date = now()->addDays(20)->next('Monday')->toDateString();

        $this->as(self::APPROVER)
            ->putJson(self::BASE . '/system-references', ['booking_type_id' => 1, 'details_values' => [$datesId => ['dates' => [$date]]]])
            ->assertOk();

        $this->assertSame([$date], $this->queuedChanges()[1]['data']['dates'], 'the list of blocked dates lives in its own table here - the message carries it');
    }

    public function test_adding_renaming_and_removing_an_option_are_told_with_the_path_of_names(): void
    {
        // Field 7 is the select of the in-unit food booking.
        $field = FormField::findOrFail(7);

        $optionId = (int) $this->as(self::SUPER_ADMIN)
            ->putJson(self::SUPER . '/update-option/7', ['option_name' => 'אפשרות חדשה', 'has_category' => 0])
            ->assertOk()->json('data.option_id');

        $this->as(self::SUPER_ADMIN)
            ->putJson(self::SUPER . '/update-option/7', ['option_id' => $optionId, 'option_name' => 'אפשרות מעודכנת', 'has_category' => 0])
            ->assertOk();

        $this->as(self::SUPER_ADMIN)->deleteJson(self::SUPER . '/remove-option/7', ['option_id' => $optionId])->assertOk();

        $changes = $this->queuedChanges();
        $this->assertSame(['create', 'update', 'delete'], array_column($changes, 'op'));
        $this->assertSame(['option_field', 'option_field', 'option_field'], array_column($changes, 'entity'));

        $this->assertSame($field->field_name, $changes[0]['field_key'], 'the field is named by its field_name, the same in both systems - not by its id');
        $this->assertSame([11, 11, 11], array_column($changes, 'booking_type_id'));
        $this->assertSame(['אפשרות חדשה'], $changes[0]['option_path']);
        $this->assertFalse($changes[0]['data']['has_category']);
        $this->assertArrayHasKey('payload_section_id', $changes[0]['data']);

        $this->assertSame(['אפשרות חדשה'], $changes[1]['option_path']);
        $this->assertSame(['name' => 'אפשרות מעודכנת'], $changes[1]['data']);

        $this->assertSame(['אפשרות מעודכנת'], $changes[2]['option_path']);

        // The ids of this system mean nothing to the other one.
        $this->assertStringNotContainsString((string) $optionId . ',', json_encode(array_column($changes, 'option_path')));
        $this->assertArrayNotHasKey('id', $changes[0]);
    }

    public function test_a_new_unit_is_told_by_its_letter_only(): void
    {
        $this->as(self::SUPER_ADMIN)->postJson(self::SUPER . '/org/units', ['code' => 'k', 'name' => 'כנף צפון'])->assertCreated();

        $file = SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->firstOrFail();
        $change = json_decode($file->payload, true)['changes'][0];

        $this->assertSame('unit', $change['entity']);
        $this->assertSame('create', $change['op']);
        $this->assertSame('K', $change['data']['code']);
        $this->assertStringNotContainsString('כנף', (string) $file->payload);
        $this->assertStringNotContainsString('internal_name', (string) $file->payload);
    }

    //----------------------------- What is refused -----------------------------------//

    public function test_a_text_with_the_name_of_a_unit_or_a_department_is_refused_and_nothing_is_saved(): void
    {
        Unit::findOrFail(2)->update(['internal_name' => 'נשרים גבוה']);
        Department::create(['unit_id' => 1, 'name' => 'מחלקת תחזוקה', 'is_active' => true]);

        $before = BookingTypeHasStatement::count();

        foreach (['ההזמנה היא ליחידת נשרים גבוה בלבד', 'לפנות אל מחלקת תחזוקה'] as $text) {
            $this->as(self::SUPER_ADMIN)
                ->putJson(self::SUPER . '/update-statement/1/1', ['statement' => $text, 'is_active' => true])
                ->assertUnprocessable()
                ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'שם פנימי'));
        }

        $this->assertSame($before, BookingTypeHasStatement::count(), 'the statement was rolled back');
        $this->assertSame(0, SyncFile::count());
        $this->assertSame(0, AuditLog::where('action', 'config.statement.update')->count(), 'and so was its audit row');

        // The same words as part of a longer word, and the unit letter, are fine.
        $this->statement('נשרים גבוהה H');
        $this->assertSame(1, SyncFile::count());
    }

    public function test_an_option_with_a_unit_name_is_refused_and_not_saved(): void
    {
        Unit::findOrFail(2)->update(['internal_name' => 'נשרים גבוה']);
        $count = OptionField::where('form_field_id', 7)->count();

        $this->as(self::SUPER_ADMIN)
            ->putJson(self::SUPER . '/update-option/7', ['option_name' => 'נשרים גבוה', 'has_category' => 0])
            ->assertUnprocessable();

        $this->assertSame($count, OptionField::where('form_field_id', 7)->count());
        $this->assertSame(0, SyncFile::count());
    }

    //----------------------------- Sending -------------------------------------------//

    public function test_the_files_go_to_the_bucket_oldest_first_and_stop_at_one_that_cannot_go(): void
    {
        $this->statement('הצהרה ראשונה');
        $this->statement('הצהרה שנייה');
        $this->statement('הצהרה שלישית');
        $this->assertSame(3, SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->count());

        // The bucket is down: the first file fails and waits out its back-off; the others wait behind it.
        config(['filesystems.storage_service' => 'no-such-disk']);
        $this->assertSame(0, $this->exportNow()['config']['sent']);
        $this->assertSame(SyncFile::STATE_FAILED, SyncFile::orderBy('id')->first()->state);

        // The bucket is back, but the first file still waits: the second must not overtake it.
        config(['filesystems.storage_service' => 'minio']);
        $this->assertSame(0, $this->exportNow()['config']['sent']);
        $this->assertSame([], Storage::disk('minio')->allFiles(self::FOLDER));

        $this->travel(10)->minutes();
        $this->assertSame(3, $this->exportNow()['config']['sent']);

        // Three files, even though they were made in the same second; sorted by name they are in the order of the changes.
        $names = array_map('basename', Storage::disk('minio')->allFiles(self::FOLDER));
        sort($names, SORT_STRING);
        $this->assertCount(3, $names);

        $texts = array_map(fn (string $name): string => json_decode(Storage::disk('minio')->get(self::FOLDER . '/' . $name), true)['changes'][0]['data']['statement'], $names);
        $this->assertSame(['הצהרה ראשונה', 'הצהרה שנייה', 'הצהרה שלישית'], $texts);
        $this->assertSame(3, SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->where('state', SyncFile::STATE_SENT)->count());
    }

    public function test_a_file_that_names_a_unit_by_the_time_it_is_sent_is_blocked_and_the_ones_after_it_wait(): void
    {
        $this->statement('לכולם יש להגיע בזמן');
        $this->statement('הצהרה אחרת לגמרי');

        // A unit is given a name after the change was queued - and the name is in the text.
        Unit::findOrFail(2)->update(['internal_name' => 'לכולם']);

        $result = $this->exportNow();
        $this->assertSame(0, $result['config']['sent']);

        $blocked = SyncFile::orderBy('id')->first();
        $this->assertSame(SyncFile::STATE_BLOCKED, $blocked->state);
        $this->assertStringContainsString('internal name', $blocked->last_error);
        $this->assertSame([], Storage::disk('minio')->allFiles(self::FOLDER), 'not even the clean one after it went out');

        // The audit log has one row for it, however many rounds pass.
        $this->exportNow();
        $this->exportNow();
        $this->assertSame(1, AuditLog::where('action', 'sync.outbound.blocked')->count());

        // The name changes: a round later both go.
        Unit::findOrFail(2)->update(['internal_name' => 'נשרים']);
        $this->assertSame(2, $this->exportNow()['config']['sent']);
        $this->assertCount(2, Storage::disk('minio')->allFiles(self::FOLDER));
    }

    public function test_the_command_sends_the_configuration_and_fails_only_when_a_file_needs_somebody(): void
    {
        $this->artisan('exportBookingStatusJson:mysql')->expectsOutputToContain('Configuration files sent: 0.')->assertExitCode(0);

        $this->statement('הצהרה לפקודה');
        $this->artisan('exportBookingStatusJson:mysql')->expectsOutputToContain('Configuration files sent: 1.')->assertExitCode(0);
        $this->assertCount(1, Storage::disk('minio')->allFiles(self::FOLDER));

        // Waiting out a back-off is not a failure of the run ...
        $this->statement('הצהרה שנכשלת');
        config(['filesystems.storage_service' => 'no-such-disk']);
        $this->artisan('exportBookingStatusJson:mysql')->expectsOutputToContain('failed')->assertExitCode(1);
        config(['filesystems.storage_service' => 'minio']);
        $this->artisan('exportBookingStatusJson:mysql')->expectsOutputToContain('waiting_to_retry')->assertExitCode(0);

        // ... a file that holds a name of a unit is: somebody has to look.
        $this->travel(10)->minutes();
        $this->artisan('exportBookingStatusJson:mysql')->expectsOutputToContain('Configuration files sent: 1.')->assertExitCode(0);

        $this->statement('לכולם יש להגיע');
        Unit::findOrFail(2)->update(['internal_name' => 'לכולם']);
        $this->artisan('exportBookingStatusJson:mysql')->expectsOutputToContain('blocked')->assertExitCode(1);
    }

    public function test_with_the_channel_off_the_changes_are_kept_and_go_out_when_it_is_switched_on(): void
    {
        config(['app.export_json.config_channel_enabled' => false]);

        $this->statement('הצהרה בזמן שהערוץ כבוי');
        $this->assertSame(SyncFile::STATE_AWAITING_SUPPORT, SyncFile::where('kind', SyncFile::KIND_SYSTEM_CONFIG)->firstOrFail()->state);

        $this->assertSame(0, $this->exportNow()['config']['sent']);
        $this->assertSame([], Storage::disk('minio')->allFiles(self::FOLDER));

        // The sync screen reads the file list, not a count per kind: the held file is listed as waiting for support.
        $overview = $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/sync')->assertOk();
        $overview->assertJsonMissingPath('data.health.config_channel_enabled')->assertJsonMissingPath('data.health.queued_config_files');
        $this->assertSame(SyncFile::STATE_AWAITING_SUPPORT, collect($overview->json('data.files'))->firstWhere('kind', 'system_config')['state']);

        config(['app.export_json.config_channel_enabled' => true]);
        $this->assertSame(1, $this->exportNow()['config']['sent']);
        $this->assertCount(1, Storage::disk('minio')->allFiles(self::FOLDER));
    }

    public function test_the_sync_screen_lists_the_config_files_and_opens_one(): void
    {
        $this->statement('הצהרה לתצוגה');

        $overview = $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/sync')->assertOk();
        $overview->assertJsonPath('data.health.blocked_files', 0);

        $row = collect($overview->json('data.files'))->firstWhere('kind', 'system_config');
        $this->assertNotNull($row);

        $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . "/sync/files/{$row['id']}")
            ->assertOk()
            ->assertJsonPath('data.records.0.entity', 'statement');
    }
}

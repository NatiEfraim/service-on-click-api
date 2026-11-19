<?php

namespace Tests\Feature;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\BookingTypeHasRole;
use App\Models\SyncConflict;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the website's screens never read is not sent (the frontend's over-fetching report): the shapes of the booking detail
 * and rows, the domain dashboard, the sync screen, the audit list, and the light handler list that replaces get-approvers
 * for a name dropdown. The figures themselves are tested where they were (ManagementInsightTest and the others).
 */
class ResponseTrimTest extends ManagementTestCase
{
    private const BASE = '/api/bookings-approver-management';
    private const ADMIN_BASE = '/api/bookings-admin-management';
    private const SUPER = '/api/bookings-super-admin-management';

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

    //----------------------------- Booking detail and rows -------------------//

    public function test_the_booking_detail_and_its_rows_leave_out_what_no_screen_reads(): void
    {
        $booking = $this->booking(['status_id' => 15]);
        $approver = User::where('personal_number', self::APPROVER)->firstOrFail();

        ApplicationLog::create(['booking_id' => $booking->id, 'action_type_id' => 3, 'status_id' => 15, 'source' => 'internal', 'user_id' => $approver->id, 'is_exported' => false]);

        $detail = $this->as(self::APPROVER)->getJson(self::BASE . '/bookings/' . $booking->uuid)->assertOk()->json('data');

        foreach (['can_edit_values', 'editable_fields', 'owner_uuid'] as $unread) {
            $this->assertArrayNotHasKey($unread, $detail);
        }

        $this->assertArrayNotHasKey('pending_export', $detail['sync']);
        $this->assertArrayHasKey('ext_status_id', $detail['sync']);
        $this->assertNotEmpty($detail['history']);
        $this->assertArrayNotHasKey('action_type_id', $detail['history'][0]);
        $this->assertArrayHasKey('action_label', $detail['history'][0]);

        // The buttons keep what the dialogs read: the move, its label, whether a comment is needed, enabled and why not.
        $this->assertNotEmpty($detail['actions']);
        $this->assertArrayNotHasKey('requires_handler', $detail['actions'][0]);
        $this->assertEqualsCanonicalizing(['status_id', 'label', 'comment_required', 'direction', 'required_fields', 'clears_fields', 'enabled', 'disabled_reason'], array_keys($detail['actions'][0]));

        // The list rows too.
        $row = $this->as(self::APPROVER)->getJson(self::BASE . '/bookings?ids=' . $booking->serial_number)->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('owner_uuid', $row);
        $this->assertArrayHasKey('owner_name', $row);
    }

    //----------------------------- Domain dashboard --------------------------//

    public function test_the_domain_dashboard_leaves_out_the_id_lists_and_the_unread_counts(): void
    {
        $this->booking();

        $data = $this->as(self::APPROVER)->getJson(self::BASE . '/domain-dashboard?types=1')->assertOk()->json('data');

        foreach (['overdue_serials', 'overdue_uuids'] as $unread) {
            $this->assertArrayNotHasKey($unread, $data);
        }

        $this->assertArrayNotHasKey('closed_in_window', $data['totals']);
        $this->assertArrayNotHasKey('sample', $data['handling']);

        // What the full analysis reads stays.
        $this->assertSame(['median_hours', 'p90_hours', 'avg_hours'], array_keys($data['handling']));
        $this->assertArrayHasKey('closed', $data['rates']);
        $this->assertArrayHasKey('window_days', $data);
        $this->assertArrayHasKey('definitions', $data);
        $this->assertArrayHasKey('overdue', $data['totals']);
        $this->assertArrayHasKey('needs_decision', $data['totals']);
    }

    //----------------------------- Sync screen -------------------------------//

    public function test_the_sync_screen_leaves_out_the_counts_and_dates_it_never_shows(): void
    {
        $booking = $this->booking(['status_source' => 'internal']);
        SyncConflict::create(['booking_id' => $booking->id, 'base_status_id' => 1, 'internal_status_id' => 15, 'external_status_id' => 4, 'external_changed_at' => now(), 'state' => SyncConflict::STATE_OPEN]);

        $data = $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/sync')->assertOk()->json('data');

        foreach (['queued_value_files', 'queued_config_files', 'config_channel_enabled'] as $unread) {
            $this->assertArrayNotHasKey($unread, $data['health']);
        }

        foreach (['export_enabled', 'last_sent_at', 'pending_decisions', 'blocked_files', 'failed_files', 'open_conflicts'] as $read) {
            $this->assertArrayHasKey($read, $data['health']);
        }

        $this->assertNotEmpty($data['conflicts']);
        $this->assertArrayNotHasKey('resolved_at', $data['conflicts'][0]);
        $this->assertArrayHasKey('detected_at', $data['conflicts'][0]);
    }

    //----------------------------- Audit list --------------------------------//

    public function test_the_audit_list_sends_the_columns_of_the_screen_and_never_the_chain_hashes(): void
    {
        $this->as(self::SUPER_ADMIN)->putJson(self::ADMIN_BASE . '/targets', ['booking_type_id' => 1, 'target_hours' => 24])->assertOk();

        $row = $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit')->assertOk()->json('data.0');

        $expected = ['id', 'occurred_at', 'actor_id', 'actor_name', 'actor_type', 'source', 'action', 'entity_type', 'entity_id', 'booking_serial', 'booking_type_id', 'unit_id', 'before', 'after', 'reason', 'result', 'correlation_id', 'booking_uuid'];

        $this->assertEqualsCanonicalizing($expected, array_keys($row));
        $this->assertArrayNotHasKey('hash', $row);
        $this->assertArrayNotHasKey('prev_hash', $row);

        // The chain is still checked, on the server.
        $this->as(self::SUPER_ADMIN)->getJson(self::SUPER . '/audit/verify')->assertOk()->assertJsonPath('data.valid', true);
    }

    //----------------------------- Handler dropdown --------------------------//

    private function handlers(string $personalNumber, string $query = ''): array
    {
        return $this->as($personalNumber)->getJson(self::BASE . '/handlers' . $query)->assertOk()->json();
    }

    /** A vice approver of the admin's unit who holds the role on one booking type only, and that type's id (not the cars, type 1). @return array{0: User, 1: int} */
    private function holderOfOneOtherType(): array
    {
        $typeId = (int) BookingTypeHasRole::query()->where('role_id', RoleEnum::VICE_APPROVER->value)->where('booking_type_id', '!=', 1)->orderBy('booking_type_id')->value('booking_type_id');
        $person = $this->createPerson('1000051', RoleEnum::VICE_APPROVER);
        $person->syncPermissions([PermissionEnum::VICE_APPROVER->keyForId($typeId)]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$person, $typeId];
    }

    public function test_the_handler_list_is_every_approver_of_the_scope_with_a_uuid_and_a_name_only(): void
    {
        // Another unit's approver, and one who holds a role on another booking type only.
        $other = $this->createPerson('1000050', RoleEnum::APPROVER, ['unit_id' => 2]);
        $this->holderOfOneOtherType();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->handlers(self::ADMIN);

        // Not paged, nothing but the two fields.
        $this->assertSame(['data'], array_keys($response));

        foreach ($response['data'] as $person) {
            $this->assertSame(['uuid', 'full_name'], array_keys($person));
        }

        $uuids = array_column($response['data'], 'uuid');
        $uuidOf = fn (string $number): string => User::where('personal_number', $number)->value('uuid');

        // The admin's own unit; no admin and no super admin; not the other unit's approver.
        $this->assertContains($uuidOf(self::APPROVER), $uuids);
        $this->assertContains($uuidOf(self::VICE_APPROVER), $uuids);
        $this->assertContains($uuidOf('1000051'), $uuids);
        $this->assertNotContains($uuidOf(self::ADMIN), $uuids);
        $this->assertNotContains($uuidOf(self::SUPER_ADMIN), $uuids);
        $this->assertNotContains($other->uuid, $uuids);

        // The super admin sees every unit.
        $this->assertContains($other->uuid, array_column($this->handlers(self::SUPER_ADMIN)['data'], 'uuid'));

        // By name.
        $names = array_column($response['data'], 'full_name');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);

        // The same people as the full, paged list of the users screen.
        foreach ([self::ADMIN, self::SUPER_ADMIN] as $actor) {
            $full = array_column($this->as($actor)->getJson(self::ADMIN_BASE . '/get-approvers')->assertOk()->json('data'), 'uuid');
            $light = array_column($this->handlers($actor)['data'], 'uuid');
            sort($full);
            sort($light);
            $this->assertSame($full, $light, $actor);
        }
    }

    public function test_the_handler_list_can_be_narrowed_to_the_holders_of_a_role_on_booking_types(): void
    {
        [$onlyOther, $otherType] = $this->holderOfOneOtherType();

        $type1 = array_column($this->handlers(self::ADMIN, '?types=1')['data'], 'uuid');
        $type2 = array_column($this->handlers(self::ADMIN, "?types={$otherType}")['data'], 'uuid');
        $both = array_column($this->handlers(self::ADMIN, "?types=1,{$otherType}")['data'], 'uuid');
        $none = $this->handlers(self::ADMIN, '?types=99999')['data'];

        $this->assertContains(User::where('personal_number', self::APPROVER)->value('uuid'), $type1);
        $this->assertNotContains($onlyOther->uuid, $type1);
        $this->assertContains($onlyOther->uuid, $type2);
        $this->assertContains($onlyOther->uuid, $both);
        $this->assertContains(User::where('personal_number', self::APPROVER)->value('uuid'), $both);
        $this->assertSame([], $none);

        // Garbage in the filter is ignored, not an error.
        $this->assertNotEmpty($this->handlers(self::ADMIN, '?types=abc,,0')['data']);
    }

    public function test_only_the_admin_and_the_super_admin_get_the_handler_list(): void
    {
        foreach ([self::APPROVER, self::VICE_APPROVER, self::PLAIN_USER, self::NO_ROLE] as $personalNumber) {
            $this->as($personalNumber)->getJson(self::BASE . '/handlers')->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson(self::BASE . '/handlers')->assertUnauthorized();
    }
}

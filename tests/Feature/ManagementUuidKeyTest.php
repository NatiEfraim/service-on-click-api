<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The management booking routes by the booking's uuid, next to the serial routes (which stay for the transition):
 * the same answers, the same scope checks, the audit still by serial - only how the booking is found changed.
 */
class ManagementUuidKeyTest extends ManagementTestCase
{
    private const API = '/api/bookings-approver-management';
    private const SERIAL = 800000001;

    private function as(string $personalNumber): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** The booking of BookingWorkflowTest: car booking, unit 1, freshly received. */
    private function booking(array $attributes = []): Booking
    {
        return Booking::create($attributes + [
            'serial_number' => self::SERIAL,
            'unit_id' => 1,
            'booking_type_id' => 1,
            'user_id' => User::where('personal_number', self::PLAIN_USER)->value('id'),
            'status_id' => 1,
            'ext_status_id' => 1,
            'status_source' => 'external',
            'status_changed_at' => now(),
            'version' => 1,
        ]);
    }

    public function test_show_by_uuid_answers_exactly_what_show_by_serial_answers(): void
    {
        $booking = $this->booking();

        $bySerial = $this->as(self::SUPER_ADMIN)->getJson(self::API . '/bookings/' . self::SERIAL)->assertOk()->json('data');
        $byUuid = $this->as(self::SUPER_ADMIN)->getJson(self::API . '/bookings/' . $booking->uuid)->assertOk()->json('data');

        $this->assertEquals($bySerial, $byUuid);
        $this->assertSame($booking->uuid, $byUuid['uuid']);
        $this->assertSame(self::SERIAL, $byUuid['serial_number']);
    }

    public function test_a_uuid_outside_the_scope_and_an_unknown_one_are_the_same_vague_404(): void
    {
        $elsewhere = $this->booking(['unit_id' => 2]);

        // The admin's scope is their own unit: the foreign uuid answers exactly like one that does not exist.
        $this->as(self::ADMIN)->getJson(self::API . '/bookings/' . $elsewhere->uuid)->assertNotFound();
        $this->as(self::ADMIN)->getJson(self::API . '/bookings/0190f3a1-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_a_status_change_by_uuid_works_and_the_audit_still_carries_the_serial(): void
    {
        $booking = $this->booking();

        $this->as(self::VICE_APPROVER)
            ->postJson(self::API . '/bookings/' . $booking->uuid . '/status', ['status_id' => 15, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status_id', 15)
            ->assertJsonPath('data.version', 2);

        // The audit row is unchanged (people search it by the number); the listing resolves the uuid next to it.
        $audit = AuditLog::where('action', 'booking.status_change')->firstOrFail();
        $this->assertSame(self::SERIAL, (int) $audit->booking_serial);

        $rows = $this->as(self::SUPER_ADMIN)
            ->getJson('/api/bookings-super-admin-management/audit?serial=' . self::SERIAL)
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rows);
        $this->assertSame(self::SERIAL, (int) $rows[0]['booking_serial']);
        $this->assertSame($booking->uuid, $rows[0]['booking_uuid']);
    }

    public function test_a_value_correction_by_uuid_works(): void
    {
        $booking = $this->booking(['status_id' => 15]);
        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 2, 'value' => now()->addDays(5)->toDateString()]);
        BookingValue::create(['booking_id' => $booking->id, 'form_field_id' => 3, 'value' => '10:00']);
        $newDate = now()->addDays(9)->toDateString();

        $this->as(self::APPROVER)
            ->putJson(self::API . '/bookings/' . $booking->uuid . '/values', ['values' => ['2' => $newDate, '3' => '11:30'], 'version' => 1, 'reason' => 'תיאום מחדש'])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertSame($newDate, BookingValue::where('booking_id', $booking->id)->where('form_field_id', 2)->value('value'));
    }

    public function test_an_attachment_opens_by_uuid_too(): void
    {
        Storage::fake('minio');
        $booking = $this->booking();
        $key = 'portal-uploads/' . $booking->uuid . '/form.pdf';
        Storage::disk('minio')->put($key, '%PDF-1.4 test');

        $file = File::create(['name' => (string) Str::uuid(), 'display_name' => 'form.pdf', 'path' => $key, 'file_name' => 'form.pdf']);
        DB::table('booking_has_files')->insert(['booking_id' => $booking->id, 'file_id' => $file->id, 'form_field_id' => null, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->as(self::SUPER_ADMIN)
            ->get(self::API . '/bookings/' . $booking->uuid . '/attachments/file/' . $file->id)
            ->assertOk();

        $this->assertSame('%PDF-1.4 test', $response->streamedContent());
    }

    public function test_the_list_filters_by_uuids(): void
    {
        $first = $this->booking();
        $second = $this->booking(['serial_number' => 800000002]);
        $this->booking(['serial_number' => 800000003]);

        $rows = $this->as(self::SUPER_ADMIN)
            ->getJson(self::API . '/bookings?uuids=' . $first->uuid . ',' . $second->uuid)
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing([$first->uuid, $second->uuid], array_column($rows, 'uuid'));

        // Junk in the filter is dropped, never an error.
        $this->as(self::SUPER_ADMIN)->getJson(self::API . '/bookings?uuids=not-a-uuid')->assertOk();
    }
}

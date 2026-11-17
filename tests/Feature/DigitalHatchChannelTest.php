<?php

namespace Tests\Feature;

use App\Models\DigitalHatchSentFile;
use App\Models\SystemReference;
use Illuminate\Support\Facades\Storage;

/**
 * The channel parameter of the six digital-hatch endpoints: channel=bucket runs the :bucket command's path (no FTP at all,
 * even while the hatch is on), channel=ftp the :ftp one; omitted, the channel follows HATCH_ENABLED like the scheduler.
 */
class DigitalHatchChannelTest extends HatchTestCase
{
    private const BASE = '/api/bookings-super-admin-management/digital-hatch';

    private function as(string $personalNumber): static
    {
        return $this->actingAsPersonalNumber($personalNumber);
    }

    public function test_export_users_channel_bucket_runs_even_while_the_hatch_is_on(): void
    {
        $data = $this->as(self::SUPER_ADMIN)->postJson(self::BASE . '/export-users?channel=bucket')->assertOk()->json('data');

        $this->assertSame('bucket', $data['channel']);
        // The old guard would have answered skipped=digital_hatch; the explicit bucket channel runs anyway.
        $this->assertArrayNotHasKey('skipped', $data['result']);
        $this->assertSame([], Storage::disk('digital-hatch')->allFiles());
    }

    public function test_export_system_references_channel_bucket_writes_the_bucket_only(): void
    {
        SystemReference::query()->update(['is_exported' => false]);

        $data = $this->as(self::SUPER_ADMIN)->postJson(self::BASE . '/export-system-references?channel=bucket')->assertOk()->json('data');

        $this->assertSame('bucket', $data['channel']);
        $this->assertTrue($data['result']['built']['written']);
        $this->assertGreaterThan(0, $data['result']['marked']);

        // Only the bucket ledger row: nothing reached the hatch.
        $this->assertSame(1, DigitalHatchSentFile::where('destination', DigitalHatchSentFile::DESTINATION_BUCKET)->count());
        $this->assertSame(0, DigitalHatchSentFile::where('destination', '!=', DigitalHatchSentFile::DESTINATION_BUCKET)->count());
        $this->assertSame([], Storage::disk('digital-hatch')->allFiles());
    }

    public function test_export_booking_status_channel_bucket_runs_the_bucket_export(): void
    {
        $data = $this->as(self::SUPER_ADMIN)->postJson(self::BASE . '/export-booking-status?channel=bucket')->assertOk()->json('data');

        $this->assertSame('bucket', $data['channel']);
        $this->assertFalse($data['queued']);
        $this->assertTrue($data['result']['enabled']);
        $this->assertSame([], Storage::disk('digital-hatch')->allFiles());
    }

    public function test_an_import_with_channel_bucket_reads_the_bucket_only(): void
    {
        $data = $this->as(self::SUPER_ADMIN)->postJson(self::BASE . '/import-booking-json?channel=bucket')->assertOk()->json('data');

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['ran']);
        $this->assertSame('bucket', $data['channel']);
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $this->as(self::SUPER_ADMIN)->postJson(self::BASE . '/export-users?channel=minio')->assertUnprocessable();
        $this->assertSame(0, DigitalHatchSentFile::count());
    }

    public function test_channel_ftp_while_the_hatch_is_off_does_nothing(): void
    {
        config(['services.digital-hatch.enabled' => false]);

        $export = $this->as(self::SUPER_ADMIN)->postJson(self::BASE . '/export-users?channel=ftp')->assertOk()->json('data');
        $this->assertFalse($export['enabled']);

        $this->app['auth']->forgetGuards();

        $import = $this->as(self::SUPER_ADMIN)->postJson(self::BASE . '/import-booking-json?channel=ftp')->assertOk()->json('data');
        $this->assertTrue($import['ok']);
        $this->assertFalse($import['ran']);
    }
}

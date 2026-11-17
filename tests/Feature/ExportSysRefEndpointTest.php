<?php

namespace Tests\Feature;

use App\Models\DigitalHatchSentFile;
use App\Models\SystemReference;
use Illuminate\Support\Facades\Storage;

/**
 * The super admin's manual trigger of the r2b system references stream (digital-hatch/export-system-references): the same
 * service the scheduler runs, so the bucket file, the digital_hatch_sent_files ledger and the is_exported flags all behave
 * exactly as in a scheduled round - and the next round never sends the same content twice.
 */
class ExportSysRefEndpointTest extends HatchTestCase
{
    private const ENDPOINT = '/api/bookings-super-admin-management/digital-hatch/export-system-references';

    private function as(string $personalNumber): static
    {
        return $this->actingAsPersonalNumber($personalNumber);
    }

    /** Flags every system reference as waiting, as a settings edit would. */
    private function markAllWaiting(): void
    {
        SystemReference::query()->update(['is_exported' => false]);
    }

    /** The destination the send records, exactly as HatchExportable decides it. */
    private function sendDestination(): string
    {
        return config('filesystems.disks.digital-hatch.driver') === 'ftp'
            ? DigitalHatchSentFile::DESTINATION_FTP
            : DigitalHatchSentFile::DESTINATION_LOCAL;
    }

    public function test_the_super_admin_sends_the_system_references_and_the_ledger_remembers_it(): void
    {
        $this->markAllWaiting();

        $data = $this->as(self::SUPER_ADMIN)->postJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $this->assertFalse($data['queued']);

        $result = $data['result'];
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['built']['written']);
        $this->assertGreaterThan(0, $result['marked']);

        $fileName = $result['file'];
        $this->assertNotNull($fileName);

        // The file reached the hatch, and the ledger holds both the bucket write and the send.
        Storage::disk('digital-hatch')->assertExists('/' . self::RED_TO_BLACK . $fileName);
        $this->assertTrue(DigitalHatchSentFile::where('file_name', $fileName)->where('destination', DigitalHatchSentFile::DESTINATION_BUCKET)->exists());
        $this->assertTrue(DigitalHatchSentFile::where('file_name', $fileName)->where('destination', $this->sendDestination())->exists());

        // Nothing waits any more: the next scheduled round has nothing to repeat.
        $this->assertSame(0, SystemReference::where('is_exported', false)->count());
    }

    public function test_the_same_content_is_not_sent_twice_and_force_sends_it_anyway(): void
    {
        $this->markAllWaiting();
        $this->as(self::SUPER_ADMIN)->postJson(self::ENDPOINT)->assertOk();
        $ledgerRows = DigitalHatchSentFile::count();

        // The same records wait again; the built file comes out identical, so the send is skipped - the flags still clear.
        $this->markAllWaiting();
        $this->app['auth']->forgetGuards();

        $second = $this->as(self::SUPER_ADMIN)->postJson(self::ENDPOINT)->assertOk()->json('data.result');

        $this->assertSame('unchanged', $second['built']['reason']);
        $this->assertSame([], $second['errors']);
        $this->assertGreaterThan(0, $second['marked']);
        $this->assertSame($ledgerRows, DigitalHatchSentFile::count());

        $sentAtBefore = DigitalHatchSentFile::where('destination', $this->sendDestination())->value('sent_at');

        // force pushes the identical content again and refreshes the ledger's sent_at.
        $this->markAllWaiting();
        $this->app['auth']->forgetGuards();
        $this->travel(2)->minutes();

        $third = $this->as(self::SUPER_ADMIN)->postJson(self::ENDPOINT . '?force=1')->assertOk()->json('data.result');

        $this->assertSame([], $third['errors']);
        $this->assertSame($ledgerRows, DigitalHatchSentFile::count());
        $this->assertNotEquals($sentAtBefore, DigitalHatchSentFile::where('destination', $this->sendDestination())->value('sent_at'));
    }

    public function test_with_the_hatch_off_the_file_goes_to_the_bucket_only(): void
    {
        config(['services.digital-hatch.enabled' => false]);
        $this->markAllWaiting();

        $result = $this->as(self::SUPER_ADMIN)->postJson(self::ENDPOINT)->assertOk()->json('data.result');

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['built']['written']);
        $this->assertGreaterThan(0, $result['marked']);
        $this->assertSame(0, DigitalHatchSentFile::where('destination', '!=', DigitalHatchSentFile::DESTINATION_BUCKET)->count());
        $this->assertSame(1, DigitalHatchSentFile::where('destination', DigitalHatchSentFile::DESTINATION_BUCKET)->count());
    }

    public function test_only_the_super_admin_may_trigger_it(): void
    {
        $this->markAllWaiting();

        $this->as(self::ADMIN)->postJson(self::ENDPOINT)->assertForbidden();
        $this->assertSame(0, DigitalHatchSentFile::count());
    }
}

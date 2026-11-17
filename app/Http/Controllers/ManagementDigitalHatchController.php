<?php

namespace App\Http\Controllers;

use App\Jobs\ExportBookingStatusJsonBucketJob;
use App\Jobs\ExportSysRefJsonBucketJob;
use App\Jobs\ExportUsersJsonBucketJob;
use App\Jobs\SendHatchBookingStatusJob;
use App\Jobs\SendHatchSystemReferencesJob;
use App\Jobs\SendHatchUsersJob;
use App\Services\BookingStatusExportService;
use App\Services\BookingStatusSnapshotService;
use App\Services\SystemReferencesExportService;
use App\Services\UsersExportService;
use App\Traits\ImportsApplicationLogJson;
use App\Traits\ImportsBookingJson;
use App\Traits\ImportsFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SUPER_ADMIN-only endpoints that manually trigger the digital-hatch commands; sync by default (sync=0 queues the work).
 * Every endpoint takes channel=ftp|bucket, exactly like the commands' :ftp / :bucket variants. Omitted, the channel follows
 * HATCH_ENABLED (on = ftp, off = bucket) - what the scheduler itself would run.
 */
class ManagementDigitalHatchController extends Controller
{
    use ImportsBookingJson, ImportsApplicationLogJson, ImportsFile;

    private const CHANNEL_FTP = 'ftp';

    private const CHANNEL_BUCKET = 'bucket';

    public function __construct(
        private readonly BookingStatusSnapshotService $bookingStatusSnapshot,
        private readonly BookingStatusExportService $bookingStatusBucketExport,
        private readonly UsersExportService $usersExport,
        private readonly SystemReferencesExportService $systemReferencesExport,
    ) {
    }

    //----------------------------- Exports (r2b) ------------------------------------//

    /** Manually run exportBookingStatusJson:ftp or :bucket; params: channel, force, sync (default 1), and on ftp booking_type_id, status_id. */
    public function exportBookingStatus(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        [$force, $sync] = $this->forceAndSync($request);

        if ($channel === self::CHANNEL_BUCKET) {
            if (!$sync) {
                return $this->queued($channel, new ExportBookingStatusJsonBucketJob());
            }

            return $this->ran($channel, $this->bookingStatusBucketExport->run());
        }

        if (!$this->hatchOn()) {
            return $this->hatchOff();
        }

        if (!$sync) {
            return $this->queued($channel, new SendHatchBookingStatusJob($force));
        }

        $bookingTypeId = $request->input('booking_type_id') !== null ? (int) $request->input('booking_type_id') : null;
        $statusId = $request->input('status_id') !== null ? (int) $request->input('status_id') : null;

        return $this->ran($channel, $this->bookingStatusSnapshot->export($force, $bookingTypeId, $statusId));
    }

    /** Manually run exportUsersJson:ftp or :bucket; params: channel, force, sync (default 1). */
    public function exportUsers(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        [$force, $sync] = $this->forceAndSync($request);

        if ($channel === self::CHANNEL_BUCKET) {
            if (!$sync) {
                return $this->queued($channel, new ExportUsersJsonBucketJob());
            }

            return $this->ran($channel, $this->usersExport->exportToBucket(evenWhileHatchOn: true));
        }

        if (!$this->hatchOn()) {
            return $this->hatchOff();
        }

        if (!$sync) {
            return $this->queued($channel, new SendHatchUsersJob($force));
        }

        return $this->ran($channel, $this->usersExport->export($force));
    }

    /** Manually run exportSysRefJson:ftp or :bucket; params: channel, force, sync (default 1). */
    public function exportSystemReferences(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        [$force, $sync] = $this->forceAndSync($request);

        if ($channel === self::CHANNEL_BUCKET) {
            if (!$sync) {
                return $this->queued($channel, new ExportSysRefJsonBucketJob());
            }

            return $this->ran($channel, $this->systemReferencesExport->exportToBucket());
        }

        if (!$this->hatchOn()) {
            return $this->hatchOff();
        }

        if (!$sync) {
            return $this->queued($channel, new SendHatchSystemReferencesJob($force));
        }

        return $this->ran($channel, $this->systemReferencesExport->export($force));
    }

    //----------------------------- Imports (b2r) ------------------------------------//

    /** Manually run importBookingJson:ftp or :bucket; params: channel, force (ftp only), sync (default 1). ran=false means disabled, queued or another run working. */
    public function importBookingJson(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        [$force, $sync] = $this->forceAndSync($request);

        return $this->importOutcome($channel, fn (): bool => $channel === self::CHANNEL_BUCKET
            ? $this->importBookingJsonFromBucket($sync)
            : $this->importBookingJsonFromFtp($force, $sync));
    }

    /** Manually run importApplicationLogJson:ftp or :bucket; params: channel, force (ftp only), sync (default 1). */
    public function importApplicationLogJson(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        [$force, $sync] = $this->forceAndSync($request);

        return $this->importOutcome($channel, fn (): bool => $channel === self::CHANNEL_BUCKET
            ? $this->importApplicationLogJsonFromBucket($sync)
            : $this->importApplicationLogJsonFromFtp($force, $sync));
    }

    /** Manually run importFile:ftp or :bucket; params: channel, force (ftp only), sync (default 1). */
    public function importFiles(Request $request): JsonResponse
    {
        $channel = $this->channel($request);
        [$force, $sync] = $this->forceAndSync($request);

        return $this->importOutcome($channel, fn (): bool => $channel === self::CHANNEL_BUCKET
            ? $this->importFileFromBucket($sync)
            : $this->importFileFromFtp($force, $sync));
    }

    //----------------------------- Shared pieces ------------------------------------//

    /** Runs an import and answers ok + ran, or ok=false with the error (the details are in laravel.log). @param callable(): bool $import */
    private function importOutcome(string $channel, callable $import): JsonResponse
    {
        try {
            return response()->json(['data' => ['ok' => true, 'ran' => $import(), 'channel' => $channel]], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json(['data' => ['ok' => false, 'error' => $e->getMessage(), 'channel' => $channel]], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** ftp | bucket (422 on anything else); omitted = what the scheduler would run: ftp while the hatch is on, bucket while it is off. */
    private function channel(Request $request): string
    {
        $validated = $request->validate(['channel' => 'nullable|string|in:ftp,bucket']);

        return $validated['channel'] ?? ($this->hatchOn() ? self::CHANNEL_FTP : self::CHANNEL_BUCKET);
    }

    /** @return array{0: bool, 1: bool} force, sync */
    private function forceAndSync(Request $request): array
    {
        return [(bool) $request->input('force', false), (bool) $request->input('sync', true)];
    }

    private function hatchOn(): bool
    {
        return (bool) config('services.digital-hatch.enabled');
    }

    /** The ftp channel was asked for while HATCH_ENABLED=false: nothing ran. */
    private function hatchOff(): JsonResponse
    {
        return response()->json(['data' => ['enabled' => false]], Response::HTTP_OK);
    }

    private function queued(string $channel, object $job): JsonResponse
    {
        dispatch($job);

        return response()->json(
            ['data' => ['enabled' => true, 'queued' => true, 'channel' => $channel, 'job' => class_basename($job)]],
            Response::HTTP_ACCEPTED,
        );
    }

    private function ran(string $channel, array $result): JsonResponse
    {
        return response()->json(['data' => ['enabled' => true, 'queued' => false, 'channel' => $channel, 'result' => $result]], Response::HTTP_OK);
    }
}

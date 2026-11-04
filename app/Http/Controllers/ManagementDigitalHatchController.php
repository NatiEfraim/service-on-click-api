<?php

namespace App\Http\Controllers;

use App\Services\BookingStatusSnapshotService;
use App\Services\UsersExportService;
use App\Traits\ImportsApplicationLogJson;
use App\Traits\ImportsBookingJson;
use App\Traits\ImportsFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SUPER_ADMIN-only endpoints that manually trigger the same logic as the scheduled digital-hatch commands:
 *   r2b export: booking status (with optional booking_type_id / status_id filter), users
 *   b2r import: booking JSON from FTP, application log JSON from FTP, files from bucket
 * All endpoints run synchronously by default (sync=1) so the caller gets the result immediately.
 * Pass sync=0 to queue the work instead (like the commands do by default; a queue worker is then needed).
 */
class ManagementDigitalHatchController extends Controller
{
    use ImportsBookingJson, ImportsApplicationLogJson, ImportsFile;

    public function __construct(
        private readonly BookingStatusSnapshotService $bookingStatusSnapshot,
        private readonly UsersExportService $usersExport,
    ) {
    }

    /**
     * Manually run digital-hatch:send-booking-status.
     * Optional params: force (bool), sync (bool, default 1), booking_type_id (int), status_id (int).
     */
    public function exportBookingStatus(Request $request): JsonResponse
    {
        if (!config('services.digital-hatch.enabled')) {
            return response()->json(['data' => ['enabled' => false]], Response::HTTP_OK);
        }

        $force = (bool) $request->input('force', false);
        $sync = (bool) $request->input('sync', true);
        $bookingTypeId = $request->input('booking_type_id') !== null ? (int) $request->input('booking_type_id') : null;
        $statusId = $request->input('status_id') !== null ? (int) $request->input('status_id') : null;

        if (!$sync) {
            $outcome = $this->exportHatchBookingStatusQueued($force);

            return response()->json(['data' => $outcome], Response::HTTP_ACCEPTED);
        }

        $result = $this->bookingStatusSnapshot->export($force, $bookingTypeId, $statusId);

        return response()->json(['data' => ['enabled' => true, 'queued' => false, 'result' => $result]], Response::HTTP_OK);
    }

    /**
     * Manually run digital-hatch:send-users (HATCH on) or exportUsersJson:bucket (HATCH off).
     * Optional params: force (bool), sync (bool, default 1).
     */
    public function exportUsers(Request $request): JsonResponse
    {
        $force = (bool) $request->input('force', false);
        $sync = (bool) $request->input('sync', true);

        if (!$sync) {
            $outcome = $this->exportHatchUsersQueued($force);

            return response()->json(['data' => $outcome], Response::HTTP_ACCEPTED);
        }

        if (config('services.digital-hatch.enabled')) {
            $result = $this->usersExport->export($force);
        } else {
            $result = $this->usersExport->exportToBucket();
        }

        return response()->json(['data' => ['enabled' => true, 'queued' => false, 'result' => $result]], Response::HTTP_OK);
    }

    /**
     * Manually run importBookingJson:ftp (takes new files from the FTP into the bucket, then imports them).
     * Optional params: force (bool), sync (bool, default 1).
     */
    public function importBookingJson(Request $request): JsonResponse
    {
        $force = (bool) $request->input('force', false);
        $sync = (bool) $request->input('sync', true);
        $outcome = $this->importBookingJsonFromFtp($force, $sync);

        return response()->json(['data' => $outcome], Response::HTTP_OK);
    }

    /**
     * Manually run importApplicationLogJson:ftp (takes new files from the FTP into the bucket, then imports them).
     * Optional params: force (bool), sync (bool, default 1).
     */
    public function importApplicationLogJson(Request $request): JsonResponse
    {
        $force = (bool) $request->input('force', false);
        $sync = (bool) $request->input('sync', true);
        $outcome = $this->importApplicationLogJsonFromFtp($force, $sync);

        return response()->json(['data' => $outcome], Response::HTTP_OK);
    }

    /**
     * Manually run importFile:bucket (saves the files and images waiting in the bucket inbox).
     * Optional params: sync (bool, default 1).
     */
    public function importFiles(Request $request): JsonResponse
    {
        $sync = (bool) $request->input('sync', true);
        $outcome = $this->importFileFromBucket($sync);

        return response()->json(['data' => $outcome], Response::HTTP_OK);
    }

    /** Queue SendHatchBookingStatusJob without running it now. */
    private function exportHatchBookingStatusQueued(bool $force): array
    {
        dispatch(new \App\Jobs\SendHatchBookingStatusJob($force));

        return ['enabled' => true, 'queued' => true, 'job' => 'SendHatchBookingStatusJob'];
    }

    /** Queue SendHatchUsersJob (hatch on) or tell the caller the hatch is off (bucket only has no queueable job). */
    private function exportHatchUsersQueued(bool $force): array
    {
        if (config('services.digital-hatch.enabled')) {
            dispatch(new \App\Jobs\SendHatchUsersJob($force));

            return ['enabled' => true, 'queued' => true, 'job' => 'SendHatchUsersJob'];
        }

        return ['enabled' => true, 'queued' => false, 'message' => 'Digital hatch is off; running bucket export now.', 'result' => $this->usersExport->exportToBucket()];
    }
}

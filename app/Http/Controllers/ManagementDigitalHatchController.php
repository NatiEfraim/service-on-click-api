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

/** SUPER_ADMIN-only endpoints that manually trigger the digital-hatch commands; sync by default (sync=0 queues the work). */
class ManagementDigitalHatchController extends Controller
{
    use ImportsBookingJson, ImportsApplicationLogJson, ImportsFile;

    public function __construct(
        private readonly BookingStatusSnapshotService $bookingStatusSnapshot,
        private readonly UsersExportService $usersExport,
    ) {
    }

    /** Manually run exportBookingStatusJson:ftp; params: force, sync (default 1), booking_type_id, status_id. */
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

    /** Manually run exportUsersJson:ftp (hatch on) or exportUsersJson:bucket (hatch off); params: force, sync (default 1). */
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

    /** Manually run importBookingJson:ftp; params: force, sync (default 1). */
    public function importBookingJson(Request $request): JsonResponse
    {
        $force = (bool) $request->input('force', false);
        $sync = (bool) $request->input('sync', true);
        $outcome = $this->importBookingJsonFromFtp($force, $sync);

        return response()->json(['data' => $outcome], Response::HTTP_OK);
    }

    /** Manually run importApplicationLogJson:ftp; params: force, sync (default 1). */
    public function importApplicationLogJson(Request $request): JsonResponse
    {
        $force = (bool) $request->input('force', false);
        $sync = (bool) $request->input('sync', true);
        $outcome = $this->importApplicationLogJsonFromFtp($force, $sync);

        return response()->json(['data' => $outcome], Response::HTTP_OK);
    }

    /** Manually run importFile:bucket; params: sync (default 1). */
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

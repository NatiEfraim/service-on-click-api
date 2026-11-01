<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveSyncConflictRequest;
use App\Services\BookingStatusExportService;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ManagementSyncController extends Controller
{
    // API group: Sync - the traffic with the mobile app (super admin): the files sent back, the decisions waiting, the conflicts.

    public function __construct(
        private readonly SyncService $syncService,
        private readonly BookingStatusExportService $exportService,
    ) {
    }

    /** GET /api/bookings-super-admin-management/sync - health, recent files, conflicts and the decisions waiting to be sent. @return JsonResponse */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->syncService->overview($this->accessScope())], Response::HTTP_OK);
    }

    /** GET /api/bookings-super-admin-management/sync/files/{id} - one file and what it carries. @param int $id @return JsonResponse */
    public function file(int $id): JsonResponse
    {
        return response()->json(['data' => $this->syncService->file($this->accessScope(), $id)], Response::HTTP_OK);
    }

    /** POST /api/bookings-super-admin-management/sync/files/{id}/retry - send a failed file again. @param int $id @return JsonResponse */
    public function retry(int $id): JsonResponse
    {
        $result = $this->exportService->retry($this->accessScope(), $id);

        return response()->json(['data' => $result], $result['sent'] ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }

    /** POST /api/bookings-super-admin-management/sync/conflicts/{id}/resolve - settle a conflict (accept_external | keep_internal, with a reason). @param int $id, ResolveSyncConflictRequest $request @return JsonResponse */
    public function resolve(int $id, ResolveSyncConflictRequest $request): JsonResponse
    {
        $result = $this->syncService->resolveConflict($this->accessScope(), $id, $request->input('resolution'), $request->input('reason'));

        return response()->json(['data' => $result, 'message' => 'ההתנגשות יושבה'], Response::HTTP_OK);
    }

    /** POST /api/bookings-super-admin-management/sync/run - send what is waiting now. @return JsonResponse */
    public function run(): JsonResponse
    {
        return response()->json(['data' => $this->syncService->run($this->accessScope())], Response::HTTP_OK);
    }
}

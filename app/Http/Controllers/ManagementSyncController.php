<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveSyncConflictRequest;
use App\Services\BookingStatusExportService;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ManagementSyncController extends Controller
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly BookingStatusExportService $exportService,
    ) {
    }

    /** Health, recent files, conflicts and the decisions waiting to be sent. */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->syncService->overview($this->accessScope())], Response::HTTP_OK);
    }

    /** One file and what it carries. */
    public function file(int $id): JsonResponse
    {
        return response()->json(['data' => $this->syncService->file($this->accessScope(), $id)], Response::HTTP_OK);
    }

    /** Send a failed file again. */
    public function retry(int $id): JsonResponse
    {
        $result = $this->exportService->retry($this->accessScope(), $id);

        return response()->json(['data' => $result], $result['sent'] ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }

    /** Settle a conflict (accept_external | keep_internal, with a reason). */
    public function resolve(int $id, ResolveSyncConflictRequest $request): JsonResponse
    {
        $result = $this->syncService->resolveConflict($this->accessScope(), $id, $request->input('resolution'), $request->input('reason'));

        return response()->json(['data' => $result, 'message' => 'ההתנגשות יושבה'], Response::HTTP_OK);
    }

    /** Send what is waiting now. */
    public function run(): JsonResponse
    {
        return response()->json(['data' => $this->syncService->run($this->accessScope())], Response::HTTP_OK);
    }
}

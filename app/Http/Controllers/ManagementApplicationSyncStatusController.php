<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplicationSyncBookingRequest;
use App\Http\Requests\ApplicationSyncUserRequest;
use App\Services\ApplicationSyncStatusService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** SUPER_ADMIN-only: read the sync state of bookings and users with the mobile application. */
class ManagementApplicationSyncStatusController extends Controller
{
    public function __construct(private readonly ApplicationSyncStatusService $syncStatus)
    {
    }

    /** All bookings with their sync state; filters: booking_type_id, status_id, serial_number, personal_number, personal_id, from/to_created_at, sent_to_application. */
    public function bookings(ApplicationSyncBookingRequest $request): JsonResponse
    {
        return response()->json($this->syncStatus->bookings($request->validated()), Response::HTTP_OK);
    }

    /** All users with their role sync state; filters: full_name, personal_number, personal_id, unit_id, sent_to_application. */
    public function users(ApplicationSyncUserRequest $request): JsonResponse
    {
        return response()->json($this->syncStatus->users($request->validated()), Response::HTTP_OK);
    }
}

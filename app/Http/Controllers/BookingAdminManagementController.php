<?php

namespace App\Http\Controllers;


use App\Enums\HttpStatusCodeEnum;
use App\Http\Requests\AssignApproverRoleRequest;
use App\Services\BookingAdminManagementService;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingAdminManagementController extends Controller
{

    // API group: Bookings Admin Management - manage approver roles in the admin's scope.

    public function __construct(
        private readonly BookingAdminManagementService $bookingAdminManagementService
    ) {
    }

    /** GET /api/bookings-admin-management/get-approvers - list approvers/vice approvers, optional ?identifier filter. @param Request $request @return JsonResponse */
    public function getApprovers(Request $request): JsonResponse
    {
        $result = $this->bookingAdminManagementService->getApprovers(
            identifier: $request->query('identifier'),
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** GET /api/bookings-admin-management/get-approver-permissions/{userUuid} - the user's approver role flags per managed booking type. @param string $userUuid @return JsonResponse */
    public function getApproverPermissions(string $userUuid): JsonResponse
    {
        $result = $this->bookingAdminManagementService->getApproverPermissions(userUuid: $userUuid);

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** POST /api/bookings-admin-management/assign-approver-role - assign/remove approver roles and permissions per booking type. @param AssignApproverRoleRequest $request @return JsonResponse */
    public function assignApproverRole(AssignApproverRoleRequest $request): JsonResponse
    {
        $result = $this->bookingAdminManagementService->assignApproverRole(
            validated: $request->validated()
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }
        return response()->json(['data' => $result], Response::HTTP_OK);
    }
}

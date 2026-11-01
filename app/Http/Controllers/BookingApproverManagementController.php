<?php

namespace App\Http\Controllers;

use App\Services\BookingApproverManagementService;
use App\Http\Requests\UpdateSystemReferenceRequest;
use Illuminate\Support\Facades\Validator;
use App\Enums\HttpStatusCodeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


class BookingApproverManagementController extends Controller
{

    public function __construct(
        private readonly BookingApproverManagementService $bookingApproverManagementService
    ) {
    }

    /** Status counts, filtered by ?filter_<id> and ?vice_approver_uuid. */
    public function getBookingsDashboard(string $bookingType, Request $request): JsonResponse
    {
        $filters = [];
        foreach ($request->query() as $key => $value) {
            $filterId = explode('_', $key)[1];
            $filters[$filterId] = is_string($value) && str_contains($value, ',')
                ? explode(',', $value)
                : $value;
        }

        $viceApproversUuids = $request->query('vice_approver_uuid')
            ? explode(',', $request->query('vice_approver_uuid'))
            : [];

        $result = $this->bookingApproverManagementService->getBookingsDashboard(
            bookingType: $bookingType,
            filters: $filters,
            viceApproversUuids: $viceApproversUuids,
        );
        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** Status counts by status_id, vice_approver_uuid, from/to. */
    public function getFilteredDashboard(string $bookingType, Request $request): JsonResponse
    {

        $statusIds = $request->query('status_id') ? explode(',', $request->query('status_id')) : [];
        $viceApproversUuids = $request->query('vice_approver_uuid') ? explode(',', $request->query('vice_approver_uuid')) : [];

        $from = $request->query('from');
        $to = $request->query('to');
       
        $rules = [
            'from' => 'nullable|date_format:d-m-Y',
            'to' => 'nullable|date_format:d-m-Y|after_or_equal:from',
        ];

        $messages = [
            'from.date_format' => 'אחד או יותר מהערכים שנשלחו אינם תקינים',
            'to.date_format' => 'אחד או יותר מהערכים שנשלחו אינם תקינים',
            'to.after_or_equal' => 'אחד או יותר מהערכים שנשלחו אינם תקינים',
        ];


        $v = Validator::make($request->all(), $rules, $messages);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filters = [
            'from' => $from,
            'to' => $to,
        ];

        $result = $this->bookingApproverManagementService->getFilteredDashboard(
            bookingType: $bookingType,
            filters: $filters,
            viceApproversUuids: $viceApproversUuids,
            statusIds: $statusIds
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::UNPROCESSABLE_ENTITY => response()->json(['message' => HttpStatusCodeEnum::UNPROCESSABLE_ENTITY->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }
        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** List the vice approvers of a booking type. */
    public function getViceApprovers(int $bookingTypeId): JsonResponse
    {
        $result = $this->bookingApproverManagementService->getViceApprovers(
            bookingTypeId: $bookingTypeId
        );
        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** Bookings by status, vice approver, serial number and date range. */
    public function getBookings(int $bookingTypeId, Request $request): JsonResponse
    {
        
        $statusIds = $request->query('status_id') ? explode(',', $request->query('status_id')) : [];
        $viceApproversUuids = $request->query('vice_approver_uuid') ? explode(',', $request->query('vice_approver_uuid')) : [];
        $serialNumber=$request->query('serial_number',null);
     
        $from = $request->query('from');
        $to = $request->query('to');
        $rules = [
            'from' => 'nullable|date_format:d-m-Y',
            'to' => 'nullable|date_format:d-m-Y|after_or_equal:from',
        ];

        $messages = [
            'from.date_format' => 'אחד או יותר מהערכים שנשלחו אינם תקינים',
            'to.date_format' => 'אחד או יותר מהערכים שנשלחו אינם תקינים',
            'to.after_or_equal' => 'אחד או יותר מהערכים שנשלחו אינם תקינים',
        ];


        $v = Validator::make($request->all(), $rules, $messages);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filters = [
            'from' => $from,
            'to' => $to,
        ];

        $result = $this->bookingApproverManagementService->getBookings(
            bookingTypeId: $bookingTypeId,
            statusIds: $statusIds,
            viceApproversUuids: $viceApproversUuids,
            filters: $filters,
            serialNumber: $serialNumber
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** Fetch the system reference settings of a booking type. */
    public function getSystemReferences(string $bookingType): JsonResponse
    {

        $result = $this->bookingApproverManagementService->getSystemReferences(
            bookingType: $bookingType
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }


    /** Update system reference details from details_values (id => fields). */
    public function updateSystemReferences(UpdateSystemReferenceRequest $request): JsonResponse
    {
       
        $result = $this->bookingApproverManagementService->updateSystemReferences(
            updatedDetails: $request->input('details_values')
        );

        return match ($result) {
            HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
            HttpStatusCodeEnum::OK => response()->json(['message' => 'השדה עודכן בהצלחה'], Response::HTTP_OK),
            default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
        };
    }


    public function getBarberBookingDate(Request $request): JsonResponse
    {
        $result = $this->bookingApproverManagementService->getBarberBookingDate(dateQuery: $request->query('date'));

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }


    public function getOrderBarberBooking(Request $request): JsonResponse
    {
        $result = $this->bookingApproverManagementService->getOrderBarberBooking(
            dateQuery: $request->query('date'),
            id: $request->query('id'),
            statusBookingId: $request->query('status_id')
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT),
            };
        }

        return response()->json([
            'data' => $result,
        ], Response::HTTP_OK);
    }
}

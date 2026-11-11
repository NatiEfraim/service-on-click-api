<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Services\RequesterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequesterController extends Controller
{
    public function __construct(private readonly RequesterService $requesterService)
    {
    }

    /** GET /requester/catalog */
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->requesterService->getCatalog()], Response::HTTP_OK);
    }

    /** GET /requester/form/{bookingTypeId} */
    public function form(int $bookingTypeId): JsonResponse
    {
        $available = $this->requesterService->availableBookingTypeIds(Auth::user());
        if (!in_array($bookingTypeId, $available, true)) {
            return response()->json(['message' => 'אין הרשאה לסוג בקשה זה'], Response::HTTP_FORBIDDEN);
        }

        $fields = $this->requesterService->getFormDetails($bookingTypeId);

        return response()->json(['data' => $fields], Response::HTTP_OK);
    }

    /** POST /bookings/{bookingTypeId} */
    public function store(StoreBookingRequest $request, int $bookingTypeId): JsonResponse
    {
        $booking = $this->requesterService->createBooking($request, $bookingTypeId);

        return response()->json(['data' => $booking], Response::HTTP_CREATED);
    }

    /** GET /requester/counters */
    public function counters(): JsonResponse
    {
        return response()->json(['data' => $this->requesterService->getCounters()], Response::HTTP_OK);
    }
}

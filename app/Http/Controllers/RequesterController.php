<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Services\RequesterService;
use Illuminate\Http\JsonResponse;
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
        $fields = $this->requesterService->getFormDetails($bookingTypeId);

        if ($fields === null) {
            return response()->json(['message' => 'לא נמצאו שדות עבור סוג הבקשה'], Response::HTTP_NOT_FOUND);
        }

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

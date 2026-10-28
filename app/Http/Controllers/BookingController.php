<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatusCodeEnum;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    // API group: Booking - what anyone who logs in may read about bookings (a person with only the user role may call just these two).

    public function __construct(
        private readonly BookingService $bookingService
    ) {
    }

    /** GET /api/bookings/{bookingUuid} - fetch one booking (serial number, status, dynamic fields). @param string $bookingUuid @return JsonResponse */
    public function show(string $bookingUuid): JsonResponse
    {
        $result = $this->bookingService->show(bookingUuid: $bookingUuid);

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT),
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** GET /api/bookings/my-bookings - auth user's bookings, filtered by status_id[], booking_type_id[], serial_number. @param Request $request @return JsonResponse */
    public function myBookings(Request $request): JsonResponse
    {
        // (array): a single value (?status_id=1) is as good as a list (?status_id[]=1).
        $statusIds = array_filter((array) $request->query('status_id', []), fn($val) => !is_null($val) && $val !== '');
        $bookingTypeIds = array_filter((array) $request->query('booking_type_id', []), fn($val) => !is_null($val) && $val !== '');
        $serialNumber = $request->query('serial_number');

        $result = $this->bookingService->myBookings(statusIds: $statusIds, bookingTypeIds: $bookingTypeIds, serialNumber: $serialNumber);

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT),
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }
}

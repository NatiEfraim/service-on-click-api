<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Services\RequesterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * GET /requester/person-lookup?booking_type_id=14&personal_id=038156246
     * The details a form's lookup field fills (personal number, names, rank) for the ID number typed; only on a type the caller may
     * submit and whose form has such a field. 200 with found true/false; 503 when the source could not be reached.
     */
    public function personLookup(Request $request): JsonResponse
    {
        $user = Auth::user();
        $bookingTypeId = (int) $request->query('booking_type_id');
        $personalId = trim((string) $request->query('personal_id', ''));

        $available = $this->requesterService->availableBookingTypeIds($user);

        if (!in_array($bookingTypeId, $available, true) || !$this->requesterService->hasPersonLookup($bookingTypeId, $user->unit_id)) {
            return response()->json(['message' => 'אין הרשאה לסוג בקשה זה'], Response::HTTP_FORBIDDEN);
        }

        if (preg_match('/^\d{9}$/', $personalId) !== 1) {
            return response()->json(['message' => 'תעודת זהות חייבת להכיל 9 ספרות בדיוק'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->requesterService->lookupPerson($user, $bookingTypeId, $personalId);

        if (!$result['available']) {
            return response()->json(['message' => 'שירות איתור הפרטים אינו זמין כעת, ניתן להזין את הפרטים ידנית'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json(['data' => [
            'found' => $result['found'],
            'person' => $result['person'],
            'message' => $result['found'] ? null : 'לא נמצאו פרטים עבור תעודת הזהות שהוזנה',
        ]], Response::HTTP_OK);
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

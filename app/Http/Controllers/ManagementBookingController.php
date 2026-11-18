<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatusCodeEnum;
use App\Exceptions\DomainError;
use App\Http\Requests\ChangeBookingStatusRequest;
use App\Http\Requests\SetBookingHandlerRequest;
use App\Http\Requests\UpdateBookingValuesRequest;
use App\Models\Booking;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Services\BookingAssignmentService;
use App\Services\BookingQueryService;
use App\Services\BookingValueService;
use App\Services\BookingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementBookingController extends Controller
{
    public function __construct(
        private readonly BookingQueryService $bookingQueryService,
        private readonly BookingWorkflowService $bookingWorkflowService,
        private readonly BookingValueService $bookingValueService,
        private readonly BookingAssignmentService $bookingAssignmentService,
        private readonly AuditService $auditService,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    /** The bookings the user may see, with stage, age, target and the actions open to them. */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bookingQueryService->list($this->accessScope(), $request->query()), Response::HTTP_OK);
    }

    /** One booking in full: values, comments, timeline, editable fields, sync state. The key is the booking's uuid or its serial. */
    public function show(string $key): JsonResponse
    {
        $serial = $this->serialOf($key);
        $booking = $serial === null ? null : $this->bookingQueryService->show($this->accessScope(), $serial);

        if ($booking === null) {
            return response()->json(['message' => HttpStatusCodeEnum::DENIED_MESSAGE], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $booking], Response::HTTP_OK);
    }

    /** One file or image of the booking (kind: file | image); images and PDFs open inline, the rest download. */
    public function attachment(string $key, string $kind, int $id): StreamedResponse
    {
        $serial = $this->serialOf($key) ?? throw new DomainError('לא נמצא', 404);

        return $this->attachmentService->open($this->accessScope(), $serial, $kind, $id);
    }

    /** Move the booking to status_id (send the version you saw; Idempotency-Key header optional; route types may send the handler values the move fills). */
    public function changeStatus(string $key, ChangeBookingStatusRequest $request): JsonResponse
    {
        $serial = $this->serialOf($key) ?? throw new DomainError('לא נמצא', 404);

        $result = $this->bookingWorkflowService->changeStatus(
            scope: $this->accessScope(),
            serial: $serial,
            targetStatusId: $request->integer('status_id'),
            comment: $request->input('comment'),
            expectedVersion: $request->integer('version'),
            idempotencyKey: $request->input('idempotency_key'),
            values: (array) $request->input('values', []),
        );

        return response()->json(['data' => $result, 'message' => $result['message']], Response::HTTP_OK);
    }

    /** Correct editable date/time values (form_field_id => value); on a route type, the handler section. */
    public function updateValues(string $key, UpdateBookingValuesRequest $request): JsonResponse
    {
        $serial = $this->serialOf($key) ?? throw new DomainError('לא נמצא', 404);

        $result = $this->bookingValueService->update(
            scope: $this->accessScope(),
            serial: $serial,
            changes: $request->input('values'),
            expectedVersion: $request->integer('version'),
            reason: $request->input('reason'),
        );

        return response()->json(['data' => $result, 'message' => $result['message']], Response::HTTP_OK);
    }

    /** Route types: assign the handler (handler_uuid), clear the assignment (null), or take the request for yourself. */
    public function setHandler(string $key, SetBookingHandlerRequest $request): JsonResponse
    {
        $serial = $this->serialOf($key) ?? throw new DomainError('לא נמצא', 404);

        $result = $this->bookingAssignmentService->assign(
            scope: $this->accessScope(),
            serial: $serial,
            handlerUuid: $request->input('handler_uuid'),
            expectedVersion: $request->integer('version'),
        );

        return response()->json(['data' => $result, 'message' => $result['handler'] === null ? 'הקצאת המטפל בוטלה' : 'המטפל הוקצה'], Response::HTTP_OK);
    }

    /** Route types: the people who may be assigned this request. */
    public function handlerCandidates(string $key): JsonResponse
    {
        $serial = $this->serialOf($key) ?? throw new DomainError('לא נמצא', 404);

        return response()->json(['data' => $this->bookingAssignmentService->candidates($this->accessScope(), $serial)], Response::HTTP_OK);
    }

    /**
     * The serial the route key names: the number itself (the serial routes), or the serial of the uuid (the uuid routes).
     * Everything downstream - scope checks, workflow rules, audit by serial - stays exactly as it was. Null = no such uuid,
     * answered with the same vague 404 every miss gets, so a uuid reveals nothing a serial would not.
     */
    private function serialOf(string $key): ?int
    {
        if (ctype_digit($key)) {
            return (int) $key;
        }

        $serial = Booking::query()->where('uuid', $key)->value('serial_number');

        return $serial === null ? null : (int) $serial;
    }

    /** This system never opens bookings: refused and audited (they are opened in the mobile app). */
    public function createBlocked(Request $request): JsonResponse
    {
        $this->auditService->denied('booking.create', 'booking', null, 'web_system_cannot_create_bookings', $request->user());

        return response()
            ->json(['message' => 'פתיחת הזמנות חדשות אינה אפשרית כאן. הזמנות נפתחות באפליקציה בלבד.'], Response::HTTP_METHOD_NOT_ALLOWED)
            ->header('Allow', 'GET');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatusCodeEnum;
use App\Http\Requests\ChangeBookingStatusRequest;
use App\Http\Requests\UpdateBookingValuesRequest;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Services\BookingQueryService;
use App\Services\BookingValueService;
use App\Services\BookingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementBookingController extends Controller
{
    // API group: Bookings Approver Management (the bookings a manager handles) - thin: every rule is in the services and in AccessScope.

    public function __construct(
        private readonly BookingQueryService $bookingQueryService,
        private readonly BookingWorkflowService $bookingWorkflowService,
        private readonly BookingValueService $bookingValueService,
        private readonly AuditService $auditService,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    /** GET /api/bookings-approver-management/bookings - the bookings the user may see, with stage, age, target and the actions open to them. @param Request $request @return JsonResponse */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bookingQueryService->list($this->accessScope(), $request->query()), Response::HTTP_OK);
    }

    /** GET /api/bookings-approver-management/bookings/{serial} - one booking in full: values, comments, timeline, editable fields, sync state. @param int $serial @return JsonResponse */
    public function show(int $serial): JsonResponse
    {
        $booking = $this->bookingQueryService->show($this->accessScope(), $serial);

        if ($booking === null) {
            return response()->json(['message' => HttpStatusCodeEnum::DENIED_MESSAGE], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $booking], Response::HTTP_OK);
    }

    /** GET /api/bookings-approver-management/bookings/{serial}/attachments/{kind}/{id} - the bytes of one file or image of the booking (kind: file | image); images and PDFs open in the page, the rest download. @param int $serial, string $kind, int $id @return StreamedResponse */
    public function attachment(int $serial, string $kind, int $id): StreamedResponse
    {
        return $this->attachmentService->open($this->accessScope(), $serial, $kind, $id);
    }

    /** POST /api/bookings-approver-management/bookings/{serial}/status - move the booking to status_id (send the version you saw; Idempotency-Key header optional). @param int $serial, ChangeBookingStatusRequest $request @return JsonResponse */
    public function changeStatus(int $serial, ChangeBookingStatusRequest $request): JsonResponse
    {
        $result = $this->bookingWorkflowService->changeStatus(
            scope: $this->accessScope(),
            serial: $serial,
            targetStatusId: $request->integer('status_id'),
            comment: $request->input('comment'),
            expectedVersion: $request->integer('version'),
            idempotencyKey: $request->input('idempotency_key'),
        );

        return response()->json(['data' => $result, 'message' => $result['message']], Response::HTTP_OK);
    }

    /** PUT /api/bookings-approver-management/bookings/{serial}/values - correct editable date/time values (form_field_id => value). @param int $serial, UpdateBookingValuesRequest $request @return JsonResponse */
    public function updateValues(int $serial, UpdateBookingValuesRequest $request): JsonResponse
    {
        $result = $this->bookingValueService->update(
            scope: $this->accessScope(),
            serial: $serial,
            changes: $request->input('values'),
            expectedVersion: $request->integer('version'),
            reason: $request->input('reason'),
        );

        return response()->json(['data' => $result, 'message' => $result['message']], Response::HTTP_OK);
    }

    /** POST|PUT /api/bookings-approver-management/bookings - this system never opens bookings: refused and audited (they are opened in the mobile app). @param Request $request @return JsonResponse */
    public function createBlocked(Request $request): JsonResponse
    {
        $this->auditService->denied('booking.create', 'booking', null, 'web_system_cannot_create_bookings', $request->user());

        return response()
            ->json(['message' => 'פתיחת הזמנות חדשות אינה אפשרית כאן. הזמנות נפתחות באפליקציה בלבד.'], Response::HTTP_METHOD_NOT_ALLOWED)
            ->header('Allow', 'GET');
    }
}

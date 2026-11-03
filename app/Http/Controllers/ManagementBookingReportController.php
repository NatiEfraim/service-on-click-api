<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingReportRequest;
use App\Services\BookingReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ManagementBookingReportController extends Controller
{
    public function __construct(private readonly BookingReportService $bookingReportService)
    {
    }

    /** The bookings the user may see, newest first, filtered by booking_type_id, status_id and from_created_at / to_created_at; one page. */
    public function index(BookingReportRequest $request): JsonResponse
    {
        return response()->json($this->bookingReportService->list($this->accessScope(), $request->validated()), Response::HTTP_OK);
    }

    /** The same bookings, all of them, as bookings_<date>_<time>.xlsx: sent back and kept in the bucket (export-report) as a backup. */
    public function export(BookingReportRequest $request): BinaryFileResponse
    {
        $file = $this->bookingReportService->export($this->accessScope(), $request->validated());

        $response = response()->download($file['path'], $file['file_name'], ['Content-Type' => BookingReportService::MIME_TYPE])->deleteFileAfterSend(true);

        // Names and ID numbers: a download is never stored by a cache (the response is "public" unless told otherwise).
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

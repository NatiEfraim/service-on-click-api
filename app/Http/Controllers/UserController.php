<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatusCodeEnum;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\TestImportRequest;
use App\Services\ImportBookingService;
use App\Services\StorageService;
use App\Services\UserService;
use App\Traits\ImportsApplicationLogJson;
use App\Traits\ImportsBookingJson;
use App\Traits\ImportsFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    use ImportsApplicationLogJson;
    use ImportsBookingJson;
    use ImportsFile;

    public function __construct(
        private readonly UserService $userService,
        private readonly ImportBookingService $importBookingService,
        private readonly StorageService $storageService,
    ) {
    }

    /** Fetch the authenticated user's details. */
    public function user(): JsonResponse
    {
        $result = $this->userService->user();

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** Paginated users, optionally filtered by personal number or name. */
    public function index(?string $identifier = null): JsonResponse
    {
        $result = $this->userService->index(
            identifier: $identifier
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

    /** Look up a person in Vatican by personal number; 404 when not found, 400 on a bad number, 503 when Vatican is unreachable. */
    public function lookupVatican(Request $request): JsonResponse
    {
        $personalNumber = trim((string) $request->query('personal_number', ''));

        if (preg_match('/^\d{7,8}$/', $personalNumber) !== 1) {
            return response()->json(['message' => 'מספר אישי חייב להכיל 7 או 8 ספרות'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->userService->lookupFromVatican($personalNumber);
        } catch (\Exception $e) {
            Log::error('Vatican lookup endpoint error: ' . $e->getMessage());
            return response()->json(['message' => 'שגיאה בגישה לשירות הפנים'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($result === null) {
            return response()->json(['message' => 'המשתמש לא נמצא'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** Create a user from personal_number, full_name, phone and unit_id. */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $result = $this->userService->store($request->only([
            'personal_number',
            'full_name',
            'phone',
            'unit_id',
            'department_id',
        ]));

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_CREATED);
    }

    /**
     * Runs the scheduled import commands' work (bookings, then logs, then files and images) inside this request, chunk jobs included, and returns
     * the imported data. ?page, ?per_page (max 50), ?booking_type_id and ?serial_number (or its start) choose which bookings are shown.
     */
    public function testImport(TestImportRequest $request): JsonResponse
    {
        try {
            // The bookings first: a log and a file belong to a booking.
            $import = [
                'bookings' => $this->importBookingJson(sync: true),
                'application_logs' => $this->importApplicationLogJson(sync: true),
                'files' => $this->importFile(),
            ];

            $imported = $this->importBookingService->importedData(
                perPage: (int) $request->query('per_page', 10),
                bookingTypeId: $request->filled('booking_type_id') ? (int) $request->query('booking_type_id') : null,
                serialNumber: $request->filled('serial_number') ? (string) $request->query('serial_number') : null,
            );
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();

            $body = ['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()];

            // The reason (e.g. a missing bucket) is for developers only: never with APP_DEBUG=false.
            if (config('app.debug')) {
                $body['error'] = $e->getMessage();
            }

            return response()->json($body, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['data' => ['import' => $import] + $imported], Response::HTTP_OK);
    }
}

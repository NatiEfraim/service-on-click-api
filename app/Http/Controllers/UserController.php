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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    use ImportsApplicationLogJson;
    use ImportsBookingJson;

    // API group: User - the auth user and user management.

    public function __construct(
        private readonly UserService $userService,
        private readonly ImportBookingService $importBookingService,
        private readonly StorageService $storageService,
    ) {
    }

    /** GET /api/user - fetch the authenticated user's details. @return JsonResponse */
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

    /** GET /api/users/{identifier?} - paginated users, optionally filtered by personal number or name. @param ?string $identifier @return JsonResponse */
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

    /** POST /api/users - create a user from personal_number, full_name, phone and unit_id. @param StoreUserRequest $request @return JsonResponse */
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
     * GET /api/test-import - read the json files waiting in the bucket right now and return the imported data.
     * It does the work of the scheduled commands (importBookingJson:mysql, then importApplicationLogJson:mysql): the same traits, services and chunk jobs,
     * except that the chunk jobs run in this request instead of waiting for the queue worker, so the data can be looked at at once.
     * ?page, ?per_page (up to 50), ?booking_type_id and ?serial_number (a serial number or its start) choose which imported bookings are shown.
     * @param TestImportRequest $request @return JsonResponse
     */
    public function testImport(TestImportRequest $request): JsonResponse
    {
        try {
            // The bookings first: a log belongs to a booking.
            $import = [
                'bookings' => $this->importBookingJson(sync: true),
                'application_logs' => $this->importApplicationLogJson(sync: true),
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

            // The reason (e.g. a bucket that does not exist) is for the developer's machine only: never with APP_DEBUG=false.
            if (config('app.debug')) {
                $body['error'] = $e->getMessage();
            }

            return response()->json($body, Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['data' => ['import' => $import] + $imported], Response::HTTP_OK);
    }
}

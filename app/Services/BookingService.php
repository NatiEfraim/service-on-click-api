<?php

namespace App\Services;

use App\Enums\HttpStatusCodeEnum;
use App\Enums\PaginationEnum;
use App\Http\Resources\MyBookingResource;
use App\Http\Resources\ShowBookingResource;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/** What anyone who logs in may read about bookings: their own history and a single booking; managing endpoints are in the *ManagementService classes. */
class BookingService
{
    public function __construct(
        private readonly StorageService $storageService,
    ) {
    }

    /** One booking by uuid; visible only to the owner (role user) or the booking's unit. */
    public function show(string $bookingUuid): ShowBookingResource|HttpStatusCodeEnum|array
    {
        try {
            $authUser = User::with(['roles'])
                ->where('id', Auth::id())
                ->first();

            $booking = Booking::with([
                'bookingType',
                'status',
                'comments',
                'user',
                'files',
                'images',
                'comments.user',
                'viceApprovers.user',
                'bookingValue.formField.fieldType',
                'bookingValue.formField.parentFormField.fieldType',
                'bookingValue.formField.parentFormField.parentFormField.fieldType',
                'bookingValue.formField.parentFormField.parentFormField.parentFormField.fieldType',
                'bookingValue.optionField.fieldType',
                'bookingValue.optionField.formField',
                'bookingValue.optionField.parentOptionField.fieldType',
                'bookingValue.optionField.parentOptionField.formField',
                'bookingValue.optionField.parentOptionField.parentOptionField.fieldType',
                'bookingValue.optionField.parentOptionField.parentOptionField.formField',
            ])
                ->where('uuid', $bookingUuid)
                ->visibleTo($authUser)
                ->first();

            if (is_null($booking)) {
                return HttpStatusCodeEnum::NOT_FOUND;
            }

            return new ShowBookingResource($booking);

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** The auth user's own bookings, paginated and filtered by status, booking type and serial. @return HttpStatusCodeEnum|AnonymousResourceCollection|array */
    public function myBookings(array $statusIds = [], array $bookingTypeIds = [], ?string $serialNumber = null): HttpStatusCodeEnum|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|array
    {
        try {
            $query = Booking::where('user_id', Auth::id())
                ->where('unit_id', Auth::user()->unit_id)
                ->with(['bookingType', 'status']);

            if (!is_null($serialNumber)) {
                $query->where('serial_number', 'like', '%' . $serialNumber . '%');
            }

            if (!empty($statusIds)) {
                $query->whereIn('status_id', $statusIds);
            }

            if (!empty($bookingTypeIds)) {
                $query->whereIn('booking_type_id', $bookingTypeIds);
            }

            // id breaks ties: bookings made in the same second must not repeat or go missing between pages.
            $myBookings = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate(PaginationEnum::PER_PAGE->value);

            return MyBookingResource::collection($myBookings);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }
}

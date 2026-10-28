<?php

namespace App\Services;

use App\Traits\InterpolatesStatement;
use App\Traits\CancelsBookingsOnRestrictionDates;
use App\Traits\ResolvesBarberSchedule;
use App\Enums\BookingTypeEnum;
use App\Enums\HttpStatusCodeEnum;
use App\Enums\PermissionEnum;
use App\Enums\PaginationEnum;
use App\Enums\SystemReferenceEnum;
use App\Http\Resources\DashboardStatusResource;
use App\Http\Resources\GetSystemReferencesResource;
use App\Models\BarberBooking;
use App\Models\BookingTypeHasStatement;
use App\Models\BookingTypeHasStatus;
use App\Models\SystemReference;
use App\Models\BookingFilter;
use App\Models\BookingType;
use App\Models\Booking;
use App\Models\Status;
use App\Models\User;
use App\Http\Resources\BookingResource;
use App\Models\BookingRestrictionDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Resources\BarberOrderBookingResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingApproverManagementService
{
    use InterpolatesStatement;
    use CancelsBookingsOnRestrictionDates;
    use ResolvesBarberSchedule;

    protected $authUser;

    public function __construct(private readonly StorageService $storageService)
    {
        $this->authUser = Auth::user();
    }



    public function getBookingsDashboard(string $bookingType, array $filters, array $viceApproversUuids): HttpStatusCodeEnum|array
    {
        try {

          
            $totalBookings = 0;
            $formattedFilters = $this->formatFilters($filters);

            $bookingType = BookingType::where('name', BookingTypeEnum::normalizeBookingTypeName($bookingType))->first();

            $dashboardQuery = Booking::with('status:id,display_name', 'bookingValue', 'viceApprovers')
                ->where('booking_type_id', $bookingType->id)
                ->where('unit_id', $this->authUser->unit_id);

            if (!empty($viceApproversUuids)) {
                $viceApproversIds = User::whereIn('uuid', $viceApproversUuids)->pluck('id')->toArray();
                $dashboardQuery->whereHas('viceApprovers', function ($query) use ($viceApproversIds) {
                    $query->whereIn('user_id', $viceApproversIds);
                });
            }
            $dashboardQueryFiltered = $this->filterQuery($dashboardQuery, $formattedFilters);
            $dashboard = $dashboardQueryFiltered->get()
                ->sortBy('status.id')
                ->groupBy('status.display_name')
                ->map(function ($bookings, $statusDisplayName) {
                    return [$statusDisplayName => $bookings->count()];
                })
                ->values()
                ->toArray();

            $allStatuses = Status::orderBy('position')
                ->whereIn('id', $this->getStatusIdsByBookingType($bookingType->id))
                ->get();

            foreach ($allStatuses as $status) {
                $statusName = $status->display_name;
                $statusCount = 0;

                foreach ($dashboard as $group) {
                    if (array_key_exists($statusName, $group)) {
                        $statusCount = $group[$statusName];
                        break;
                    }
                }

                $status->count = $statusCount;
                $totalBookings += $statusCount;
            }

            $formattedDashboard = DashboardStatusResource::collection($allStatuses)->resolve();
            $formattedDashboard['total_bookings'] = $totalBookings;

            $user = User::where('id', $this->authUser->id)->first();
            $authUserPermission = $user->getAllPermissions()
                ->filter(function ($permission) use ($bookingType) {
                    return str_ends_with($permission->name, '_' . $bookingType->id);
                })
                ->value('name');

            return [
                'dashboard' => $formattedDashboard,
                'booking_type' => $bookingType,
                'auth_user_permission' => $authUserPermission,
            ];
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Build dashboard status counts and total_bookings for a filtered subset (incl. statuses). @param string $bookingType, array $filters, array $viceApproversUuids, array $statusIds @return array|HttpStatusCodeEnum */
    public function getFilteredDashboard(
        string $bookingType,
        array $filters = [],
        array $viceApproversUuids = [],
        array $statusIds = []
    ): array|HttpStatusCodeEnum {

        try {

       
            $bookingType = BookingType::where('name', BookingTypeEnum::normalizeBookingTypeName($bookingType))->first();
            if (is_null($bookingType)) {
                return HttpStatusCodeEnum::UNPROCESSABLE_ENTITY;
            }
            $base = $this->baseBookingsQuery($bookingType->id, $filters, $viceApproversUuids);

            if (!empty($statusIds)) {
                $base->whereIn('status_id', $statusIds);
            }

            // Aggregate in SQL
            $counts = (clone $base)
                ->select('status_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('status_id')
                ->pluck('cnt', 'status_id')
                ->toArray();

            $allStatuses = Status::orderBy('position')
                ->whereIn('id', $this->getStatusIdsByBookingType($bookingType->id))
                ->get();

            $total = 0;

            foreach ($allStatuses as $status) {
                $count = (int) ($counts[$status->id] ?? 0);
                $status->count = $count;
                $total += $count;
            }

            $formatted = DashboardStatusResource::collection($allStatuses)->resolve();
            $formatted['total_bookings'] = $total;
            $user = User::where('id', $this->authUser->id)->first();
            $authUserPermission = $user->getAllPermissions()
                ->filter(function ($permission) use ($bookingType) {
                    return str_ends_with($permission->name, '_' . $bookingType->id);
                })
                ->value('name');

            return [
                'dashboard' => $formatted,
                'booking_type' => $bookingType,
                'auth_user_permission' => $authUserPermission,
            ];

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Get the auth unit's vice approvers (uuid, full_name) for a booking type. @param int $bookingTypeId @return HttpStatusCodeEnum|array */
    public function getViceApprovers(int $bookingTypeId): HttpStatusCodeEnum|array
    {
        try {

            return User::where('unit_id', $this->authUser->unit_id)
                ->permission(PermissionEnum::VICE_APPROVER->keyForId($bookingTypeId))
                ->get()
                ->map(function ($userModel): array {
                    return [
                        'uuid' => $userModel->uuid,
                        'full_name' => $userModel->full_name,
                    ];
                })
                ->toArray();

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Paginate the unit's bookings of a type, filtered by status, vice approvers, dates and serial. @param int $bookingTypeId, array $statusIds, array $viceApproversUuids, array $filters, ?string $serialNumber @return HttpStatusCodeEnum|AnonymousResourceCollection|array */
    public function getBookings(
        int $bookingTypeId,
        array $statusIds,
        array $viceApproversUuids,
        array $filters,
        ?string $serialNumber = null
    ): HttpStatusCodeEnum|AnonymousResourceCollection|array {


        try {

            $bookingQuery = Booking::with([
                'bookingType',
                'status',
                'user',
                'comments.user',
                'viceApprovers.user',

                // Uploads, each tagged with its form field - shown inside booking_values by BookingValueFormatterService.
                'files',
                'images',

                'bookingValue.formField.fieldType',
                'bookingValue.formField.systemReferences',
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
                ->where('booking_type_id', $bookingTypeId)
                ->where('unit_id', $this->authUser->unit_id);
            if (!is_null($serialNumber)) {
                $bookingQuery->where('serial_number', 'like', '%' . $serialNumber . '%');
            }

            if (!empty($statusIds)) {
                $bookingQuery->whereIn('status_id', $statusIds);
            }

            if (!empty($viceApproversUuids)) {

                $viceApproversIds = User::whereIn('uuid', $viceApproversUuids)->get()->pluck('id')->toArray();

                $bookingQuery->whereHas('viceApprovers', function ($bookingQuery) use ($viceApproversIds) {
                    $bookingQuery->whereIn('user_id', $viceApproversIds);
                });
            }

            $from = $filters['from'] ?? null; // "d-m-Y"
            $to = $filters['to'] ?? null; // "d-m-Y"

            $fromAt = $from ? Carbon::createFromFormat('d-m-Y', $from)->startOfDay() : null;
            $toAt = $to ? Carbon::createFromFormat('d-m-Y', $to)->endOfDay() : null;

            if ($fromAt && $toAt) {
                $bookingQuery->whereBetween('created_at', [$fromAt, $toAt]);
            } elseif ($fromAt) {
                $bookingQuery->where('created_at', '>=', $fromAt);
            } elseif ($toAt) {
                $bookingQuery->where('created_at', '<=', $toAt);
            }

            $bookings = $bookingQuery->orderBy('created_at', 'desc')
                ->paginate(PaginationEnum::PER_PAGE->value);

            return BookingResource::collection($bookings);

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Get the unit's system references and statement for a booking type. @param string $bookingType @return array|HttpStatusCodeEnum */
    public function getSystemReferences(string $bookingType): array|HttpStatusCodeEnum
    {
        try {

            $bookingTypeId = BookingTypeEnum::getBookingTypeIdFromRouteName($bookingType);

            if (is_null($bookingTypeId)) {
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            $statement = BookingTypeHasStatement::query()
                ->where('unit_id', $this->authUser->unit_id)
                ->where('booking_type_id', $bookingTypeId)
                ->value('statement');

            // Interpolate the statement with dynamic values from system references
            $interpolatedStatement = $this->interpolateStatement($statement, $this->authUser->unit_id);

            $systemReferences = SystemReference::query()
                ->with([
                    'bookingType',
                    'unit',
                    'fieldType',
                ])
                ->where('booking_type_id', $bookingTypeId)
                ->where('unit_id', $this->authUser->unit_id)
                ->get();

            return (new GetSystemReferencesResource([
                'booking_type_id' => $bookingTypeId,
                'system_references' => $systemReferences,
                'statement' => $interpolatedStatement,
            ]))->resolve();

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

   

    /** List barber service dates with taken count, service and break hours (optionally one date). @param string $dateQuery @return HttpStatusCodeEnum|AnonymousResourceCollection|array */
    public function getBarberBookingDate(string $dateQuery = null): HttpStatusCodeEnum|AnonymousResourceCollection|array
    {

        try {

            if ($dateQuery !== null && !Carbon::hasFormat($dateQuery, 'Y-m-d')) {
                return HttpStatusCodeEnum::UNPROCESSABLE_ENTITY;
            } else if ($dateQuery) {
                $dateQuery = Carbon::createFromFormat('Y-m-d', $dateQuery)->toDateString();
            }


            $serviceHours = $this->barberServiceHours();
            $breakHours = $this->barberBreakHours();

            $serviceStart = $serviceHours['start_time'] ?? null;
            $serviceEnd = $serviceHours['end_time'] ?? null;
            $breakStart = $breakHours['start_time'] ?? null;
            $breakEnd = $breakHours['end_time'] ?? null;

            if (!$serviceStart || !$serviceEnd || !$breakStart || !$breakEnd) {
                return [];
            }

            $haircutTime = $this->barberHaircutTime();

            $takenPerDate = BarberBooking::query()
                ->where('is_deleted', false)
                ->selectRaw('date, COUNT(*) as taken')
                ->groupBy('date')
                ->pluck('taken', 'date');

            $dates = $this->barberServiceDates();

            if ($dateQuery) {
                $dates = array_values(array_filter(
                    $dates,
                    fn(string $date): bool => $date === $dateQuery,
                ));
            }

            return array_map(
                fn(string $date): array => [
                    'date' => $date,
                    'taken' => $takenPerDate[$date] ?? 0,
                    'day_name' => $this->hebrewDayName(Carbon::parse($date)),
                    'haircut_time' => $haircutTime,
                    'activity' => true,

                    'service_hours' => [
                        'start_time' => Carbon::parse($serviceStart)->format('H:i'),
                        'end_time' => Carbon::parse($serviceEnd)->format('H:i'),
                    ],

                    'break_hour' => [
                        'start_time' => Carbon::parse($breakStart)->format('H:i'),
                        'end_time' => Carbon::parse($breakEnd)->format('H:i'),
                    ],
                ],
                $dates,
            );

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** List active barber bookings by date/start time, filtered by date, id and status. @param string $dateQuery, int $id, mixed $statusBookingId @return HttpStatusCodeEnum|AnonymousResourceCollection */
    public function getOrderBarberBooking(string $dateQuery = null, int $id = null, $statusBookingId = null): HttpStatusCodeEnum|AnonymousResourceCollection
    {

        try {

            $statusIds = $statusBookingId
                ? [(int) $statusBookingId]
                : $this->getStatusIdsByBookingType(BookingTypeEnum::BARBER_BOOKING->getBookingTypeId());

            $barberBookings = BarberBooking::query()
                ->with([
                    'user',
                    'booking.status',
                    'booking.viceApprovers.user',
                ])
                ->where('is_deleted', false)
                ->whereHas('booking', function ($bookingQuery) use ($statusIds) {
                    $bookingQuery
                        ->where('booking_type_id', BookingTypeEnum::BARBER_BOOKING->getBookingTypeId())
                        ->whereIn('status_id', $statusIds);
                })
                ->when($dateQuery, function ($query) use ($dateQuery) {
                    $query->whereDate('date', $dateQuery);
                })
                ->when($id, function ($query) use ($id) {
                    $query->where('id', $id);
                })
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();

            return BarberOrderBookingResource::collection($barberBookings);

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Update the unit's system reference values by id; restriction dates sync booking blocks. @param array $updatedDetails @return array|HttpStatusCodeEnum */
    public function updateSystemReferences(array $updatedDetails = []): array|HttpStatusCodeEnum
    {
        try {

            DB::beginTransaction();

            foreach ($updatedDetails as $id => $fields) {

                $record = SystemReference::where('id', $id)->where('unit_id', Auth::user()->unit_id)->first();

                $currentDetails = $record->decodedValue();

                foreach ($fields as $key => $value) {

                    if (array_key_exists($key, $currentDetails)) {
                        if (
                            SystemReferenceEnum::isRestrictionDatesReference(
                                bookingTypeId: (int) $record->booking_type_id,
                                systemReferenceId: (int) $id,
                            )
                            && is_array($value)
                        ) {
                    
                            $this->syncBookingRestrictionDates(
                                bookingTypeId: (int) $record->booking_type_id,
                                dates: $value,
                            );
                        } else {
                            $currentDetails[$key] = $value;
                        }
                    }
                }

                $record->update([
                    'value' => json_encode($currentDetails),
                ]);
            }

            DB::commit();
            return HttpStatusCodeEnum::OK;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** The pool of status ids valid for a given booking type. */
    private function getStatusIdsByBookingType(int $bookingTypeId): array
    {
        return BookingTypeHasStatus::query()
            ->where('booking_type_id', $bookingTypeId)
            ->pluck('status_id')
            ->map(fn($statusId) => (int) $statusId)
            ->values()
            ->toArray();
    }

    /** Sync booking restriction dates to match the frontend's final dates array. */
    private function syncBookingRestrictionDates(int $bookingTypeId, array $dates): void
    {

        $dates = collect($dates)
            ->filter()
            ->map(function ($date) {
                return Carbon::createFromFormat('Y-m-d', $date)->toDateString();
            })
            ->unique()
            ->values()
            ->toArray();

        // Dates already blocked before this update - anything beyond these is a newly added block.
        $existingDates = BookingRestrictionDate::query()
            ->where('booking_type_id', $bookingTypeId)
            ->pluck('date')
            ->map(fn($date) => Carbon::parse($date)->toDateString())
            ->toArray();

        if (empty($dates)) {
            BookingRestrictionDate::query()
                ->where('booking_type_id', $bookingTypeId)
                ->delete();

            return;
        }

        // Delete dates no longer in the submitted final array.
        BookingRestrictionDate::query()
            ->where('booking_type_id', $bookingTypeId)
            ->whereNotIn('date', $dates)
            ->delete();

        foreach ($dates as $date) {
            BookingRestrictionDate::query()->firstOrCreate([
                'booking_type_id' => $bookingTypeId,
                'date' => $date,
            ]);
        }

        // A date just became blocked - cancel any booking already sitting on it.
        $newlyBlockedDates = array_values(array_diff($dates, $existingDates));

        $this->cancelBookingsOnNewlyRestrictedDates(
            bookingTypeId: $bookingTypeId,
            dates: $newlyBlockedDates,
        );
    }



    private function baseBookingsQuery(
        int $bookingTypeId,
        array $filters,
        array $viceApproversUuids
    ): Builder {

        $q = Booking::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', Auth::user()->unit_id);

        if (!empty($viceApproversUuids)) {
            $viceApproversIds = User::whereIn('uuid', $viceApproversUuids)->pluck('id')->toArray();
            $q->whereHas('viceApprovers', fn($sub) => $sub->whereIn('user_id', $viceApproversIds));
        }

        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $fromAt = $from ? \Carbon\Carbon::createFromFormat('d-m-Y', $from)->startOfDay() : null;
        $toAt = $to ? \Carbon\Carbon::createFromFormat('d-m-Y', $to)->endOfDay() : null;

        if ($fromAt && $toAt) {
            $q->whereBetween('created_at', [$fromAt, $toAt]);
        } elseif ($fromAt) {
            $q->where('created_at', '>=', $fromAt);
        } elseif ($toAt) {
            $q->where('created_at', '<=', $toAt);
        }

        return $q;
    }


    /** Apply advanced filter logic to the query using form field values (including date ranges). */
    private function filterQuery($query, $filters): mixed
    {
        foreach ($filters as $filter) {
            $filterIsStartDate = Str::startsWith($filter['operator'], '-');
            $filterIsEndDate = Str::endsWith($filter['operator'], '-');

            if ($filterIsStartDate) {
                foreach ($filters as $otherFilter) {
                    if (Str::endsWith($otherFilter['operator'], '-')) {
                        $operator = explode('-', $otherFilter['operator'])[0];
                        $endDate = $otherFilter['filter_value'];
                    }
                }
                $query = $query->whereHas('bookingValue', function ($subQuery) use ($filter, $operator, $endDate) {
                    $subQuery->where('form_field_id', $filter['form_field_id'])
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(value, '$[0]')) $operator ?", [$endDate]);

                });
            } else if ($filterIsEndDate) {
                foreach ($filters as $otherFilter) {
                    if (Str::startsWith($otherFilter['operator'], '-')) {
                        $operator = explode('-', $otherFilter['operator'])[1];
                        $startDate = $otherFilter['filter_value'];
                    }
                }
                $query = $query->whereHas('bookingValue', function ($subQuery) use ($filter, $operator, $startDate) {
                    $subQuery->where('form_field_id', $filter['form_field_id'])
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(value, '$[0]')) $operator ?", [$startDate]);
                });
            } else {
                $query = $query->whereHas('bookingValue', function ($subQuery) use ($filter) {
                    $subQuery->where('form_field_id', $filter['form_field_id'])
                        ->where('value', $filter['operator'], $filter['filter_value']);
                });
            }
        }
        return $query;
    }

    /** Format given filters by merging filter values with form field and operator data. */
    private function formatFilters($filters): mixed
    {
        $filtersIds = [];
        $formattedFilters = [];
        foreach ($filters as $filterId => $filterValue) {
            $filtersIds[] = $filterId;
            $formattedFilters[] = [
                'filter_id' => $filterId,
                'filter_value' => $filterValue,
            ];
        }

        $formFieldsFilterIds = BookingFilter::select('id', 'form_field_id', 'operator')
            ->whereIn('id', $filtersIds)
            ->get()
            ->toArray();

        $mergedFormattedFilters = [];
        foreach ($formFieldsFilterIds as $field) {
            foreach ($formattedFilters as $formattedFilter) {
                if ($formattedFilter['filter_id'] == $field['id']) {
                    $mergedFormattedFilters[] = array_merge($field, ['filter_value' => $formattedFilter['filter_value']]);
                    break;
                }
            }
        }

        return $mergedFormattedFilters;
    }

    private function hebrewDayName(Carbon $date): string
    {
        return match ($date->dayOfWeek) {
            Carbon::SUNDAY => 'יום ראשון',
            Carbon::MONDAY => 'יום שני',
            Carbon::TUESDAY => 'יום שלישי',
            Carbon::WEDNESDAY => 'יום רביעי',
            Carbon::THURSDAY => 'יום חמישי',
            Carbon::FRIDAY => 'יום שישי',
            Carbon::SATURDAY => 'שבת',
        };
    }
}

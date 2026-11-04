<?php

namespace App\Services;

use App\Enums\BookingStageEnum;
use App\Exceptions\DomainError;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Department;
use App\Models\Unit;
use App\Support\AccessScope;

/** Report generator: flat rows with user-picked columns for the preview and Excel/PDF export. */
class ReportService
{
    public const MAX_ROWS = 5000;

    public function __construct(private readonly BookingQueryService $bookings)
    {
    }

    /** @param array<string, mixed> $filters serial, types, groups, statuses, units, departments, requester, personal_id, opened/updated from/to, stage, overdue, min_age_days @return array{rows: array<int, array<string, mixed>>, total: int, truncated: bool} */
    public function rows(AccessScope $scope, array $filters): array
    {
        if (!$scope->can('reports.view')) {
            throw new DomainError('מחולל הדוחות זמין למנהל מערכת ולמפקד מחנה', 403);
        }

        $types = BookingType::query()->with('groupBookingTypes:id,display_name')->get()->keyBy('id');
        $units = Unit::query()->get()->mapWithKeys(fn (Unit $unit): array => [$unit->id => $unit->displayName()]);
        $departments = Department::query()->pluck('name', 'id');
        $ids = fn (string $key): array => array_values(array_filter(array_map('intval', explode(',', (string) ($filters[$key] ?? '')))));

        $query = $scope->applyToBookings(Booking::query())
            ->with(['user:id,uuid,full_name,personal_id,personal_number,department_id', 'bookingType:id,display_name', 'status:id,display_name']);

        if (!$scope->isSuperAdmin()) {
            $query->whereIn('booking_type_id', $scope->dashboardBookingTypeIds() ?: [-1]);
        }

        if (($serial = trim((string) ($filters['serial'] ?? ''))) !== '') {
            $query->where('serial_number', 'like', '%' . addcslashes($serial, '%_\\') . '%');
        }

        if ($typeIds = $ids('types')) {
            $query->whereIn('booking_type_id', $typeIds);
        }

        if ($groupIds = $ids('groups')) {
            $inGroups = $types->filter(fn (BookingType $type): bool => $type->groupBookingTypes->pluck('id')->intersect($groupIds)->isNotEmpty())->keys()->all();
            $query->whereIn('booking_type_id', $inGroups ?: [-1]);
        }

        if ($statusIds = $ids('statuses')) {
            $query->whereIn('status_id', $statusIds);
        }

        if ($unitIds = $ids('units')) {
            $query->whereIn('unit_id', $unitIds);
        }

        if ($departmentIds = $ids('departments')) {
            $query->whereHas('user', fn ($user) => $user->whereIn('department_id', $departmentIds));
        }

        if (($requester = trim((string) ($filters['requester'] ?? ''))) !== '') {
            $query->whereHas('user', fn ($user) => $user->where('full_name', 'like', '%' . addcslashes($requester, '%_\\') . '%'));
        }

        if (($personalId = trim((string) ($filters['personal_id'] ?? ''))) !== '') {
            $query->whereHas('user', fn ($user) => $user->where('personal_id', 'like', addcslashes($personalId, '%_\\') . '%'));
        }

        foreach (['opened' => 'created_at', 'updated' => 'status_changed_at'] as $key => $column) {
            if (!empty($filters["{$key}_from"])) {
                $query->whereDate($column, '>=', $filters["{$key}_from"]);
            }

            if (!empty($filters["{$key}_to"])) {
                $query->whereDate($column, '<=', $filters["{$key}_to"]);
            }
        }

        // Stage, target and age filters also run in the database, so only the rows shown are read.
        if (!empty($filters['stage'])) {
            $this->bookings->limitToStage($query, $scope->workflow(), BookingStageEnum::tryFrom((string) $filters['stage']));
        }

        if (in_array($filters['overdue'] ?? '', ['yes', 'no'], true)) {
            $this->bookings->limitToOverdue($query, $filters['overdue'] === 'yes');
        }

        if (is_numeric($filters['min_age_days'] ?? null)) {
            $this->bookings->limitToMinimumAge($query, (float) $filters['min_age_days']);
        }

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get()->map(function (Booking $booking) use ($scope, $types, $units, $departments): array {
            $row = $this->bookings->row($booking, $scope);
            $type = $types[$booking->booking_type_id] ?? null;
            $closed = $row['stage'] === BookingStageEnum::CLOSED->value;
            $group = $type?->groupBookingTypes->first();
            $departmentId = $booking->user?->department_id;

            return [
                'serial_number' => $row['serial_number'],
                'booking_type_id' => $row['booking_type_id'],
                'booking_type_label' => $row['booking_type_label'],
                'group_id' => $group?->id,
                'group_label' => $group?->display_name ?? $type?->display_name ?? '',
                'status_id' => $row['status_id'],
                'status_label' => $row['status_label'],
                'stage' => $row['stage'],
                'stage_label' => $row['stage_label'],
                'owner_name' => $row['owner_name'],
                'owner_personal_id' => $row['owner_personal_id'],
                'unit_id' => $booking->unit_id,
                'unit_name' => $units[$booking->unit_id] ?? 'ללא שיוך',
                'department_id' => $departmentId,
                'department_name' => $departments[$departmentId] ?? 'ללא מחלקה',
                'opened' => $row['opened_at'],
                'updated' => $row['status_changed_at'],
                'total_age_hours' => $closed ? null : $row['total_age_hours'],
                'target_hours' => $row['target_hours'],
                'overdue' => $row['overdue'],
                'handling_hours' => $closed ? round($booking->openedAt()->diffInMinutes($booking->statusSince(), true) / 60, 1) : null,
            ];
        });

        return ['rows' => $rows->values()->all(), 'total' => $total, 'truncated' => $total > self::MAX_ROWS];
    }
}

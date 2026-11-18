<?php

namespace App\Services;

use App\Enums\BookingTypeEnum;
use App\Models\Booking;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only view of how many bookings and users have been synced to the mobile application.
 * A booking is "sent" when is_exported, is_status_exported are both true and is_updated_value is false.
 * A user is "sent" when is_exported_role is true.
 */
class ApplicationSyncStatusService
{
    private const MAX_PER_PAGE = 100;

    private const BOOKING_SELECT = [
        'id', 'uuid', 'serial_number', 'unit_id', 'booking_type_id', 'user_id',
        'status_id', 'status_changed_at', 'created_at',
        'is_exported', 'is_status_exported', 'is_updated_value',
    ];

    private const BOOKING_RELATIONS = [
        'user:id,full_name,personal_id,personal_number',
        'bookingType:id,display_name',
        'status:id,display_name',
    ];

    private const USER_RELATIONS = ['roles:id,name', 'unit:id,name,internal_name'];

    /** @param array<string, mixed> $filters @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, last_page: int, per_page: int}} */
    public function bookings(array $filters): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), self::MAX_PER_PAGE));
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $units   = $this->unitNames();

        $paginator = $this->bookingQuery($filters)
            ->with(self::BOOKING_RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, self::BOOKING_SELECT, 'page', $page);

        return [
            'data' => array_map(
                fn (Booking $b): array => $this->bookingRow($b, $units),
                $paginator->items()
            ),
            'meta' => [
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page'  => $perPage,
            ],
        ];
    }

    /** @param array<string, mixed> $filters @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, last_page: int, per_page: int}} */
    public function users(array $filters): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), self::MAX_PER_PAGE));
        $page    = max(1, (int) ($filters['page'] ?? 1));

        $paginator = $this->userQuery($filters)
            ->with(self::USER_RELATIONS)
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate($perPage, ['id', 'uuid', 'full_name', 'personal_number', 'personal_id', 'phone', 'unit_id', 'department_id', 'is_exported_role'], 'page', $page);

        return [
            'data' => array_map(
                fn (User $u): array => $this->userRow($u),
                $paginator->items()
            ),
            'meta' => [
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page'  => $perPage,
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function bookingQuery(array $filters): Builder
    {
        $query = Booking::query();

        // The web-only types (infosec, ICT) are never sent to the mobile app: they have no sync state to show.
        $webOnlyTypeIds = BookingTypeEnum::webOnlyIds();

        if ($webOnlyTypeIds !== []) {
            $query->whereNotIn('booking_type_id', $webOnlyTypeIds);
        }

        if (filled($filters['booking_type_id'] ?? null)) {
            $query->where('booking_type_id', (int) $filters['booking_type_id']);
        }

        if (filled($filters['status_id'] ?? null)) {
            $query->where('status_id', (int) $filters['status_id']);
        }

        if (filled($filters['serial_number'] ?? null)) {
            $query->where('serial_number', 'like', '%' . $filters['serial_number'] . '%');
        }

        // Filter by user fields via a join-free subquery.
        if (filled($filters['personal_number'] ?? null)) {
            $query->whereHas('user', fn (Builder $u) => $u->where('personal_number', 'like', '%' . $filters['personal_number'] . '%'));
        }

        if (filled($filters['personal_id'] ?? null)) {
            $query->whereHas('user', fn (Builder $u) => $u->where('personal_id', 'like', '%' . $filters['personal_id'] . '%'));
        }

        if (filled($filters['from_created_at'] ?? null)) {
            $query->where('created_at', '>=', $this->dayStart((string) $filters['from_created_at']));
        }

        if (filled($filters['to_created_at'] ?? null)) {
            $query->where('created_at', '<', $this->dayStart((string) $filters['to_created_at'], 1));
        }

        if (isset($filters['sent_to_application'])) {
            $sent = filter_var($filters['sent_to_application'], FILTER_VALIDATE_BOOLEAN);

            if ($sent) {
                $query->where('is_exported', true)->where('is_status_exported', true)->where('is_updated_value', false);
            } else {
                $query->where(fn (Builder $w) => $w
                    ->where('is_exported', false)
                    ->orWhere('is_status_exported', false)
                    ->orWhere('is_updated_value', true));
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function userQuery(array $filters): Builder
    {
        $query = User::query()->where('is_deleted', false);

        if (filled($filters['full_name'] ?? null)) {
            $query->where('full_name', 'like', '%' . $filters['full_name'] . '%');
        }

        if (filled($filters['personal_number'] ?? null)) {
            $query->where('personal_number', 'like', '%' . $filters['personal_number'] . '%');
        }

        if (filled($filters['personal_id'] ?? null)) {
            $query->where('personal_id', 'like', '%' . $filters['personal_id'] . '%');
        }

        if (filled($filters['unit_id'] ?? null)) {
            $query->where('unit_id', (int) $filters['unit_id']);
        }

        if (isset($filters['sent_to_application'])) {
            $sent = filter_var($filters['sent_to_application'], FILTER_VALIDATE_BOOLEAN);
            $query->where('is_exported_role', $sent);
        }

        return $query;
    }

    /** @param array<int, string> $units @return array<string, mixed> */
    private function bookingRow(Booking $booking, array $units): array
    {
        $exported       = (bool) $booking->is_exported;
        $statusExported = (bool) $booking->is_status_exported;
        $valueUpdated   = (bool) $booking->is_updated_value;

        return [
            'uuid'                 => $booking->uuid,
            'serial_number'        => $booking->serial_number === null ? null : (int) $booking->serial_number,
            'booking_type_id'      => (int) $booking->booking_type_id,
            'booking_type_label'   => $booking->bookingType?->display_name,
            'status_id'            => (int) $booking->status_id,
            'status_label'         => $booking->status?->display_name,
            'unit_id'              => $booking->unit_id === null ? null : (int) $booking->unit_id,
            'unit_name'            => $units[$booking->unit_id] ?? null,
            'owner_name'           => $booking->user?->full_name,
            'owner_personal_id'    => $booking->user?->personal_id,
            'owner_personal_number'=> $booking->user?->personal_number,
            'created_at'           => $booking->openedAt()->toIso8601String(),
            'status_changed_at'    => $booking->statusSince()->toIso8601String(),
            // Sync state breakdown.
            'is_exported'          => $exported,
            'is_status_exported'   => $statusExported,
            'is_updated_value'     => $valueUpdated,
            // true = fully in sync with the mobile application.
            'sent_to_application'  => $exported && $statusExported && !$valueUpdated,
        ];
    }

    /** @return array<string, mixed> */
    private function userRow(User $user): array
    {
        $exported = (bool) $user->is_exported_role;

        return [
            'uuid'                => $user->uuid,
            'full_name'           => $user->full_name,
            'personal_number'     => $user->personal_number,
            'personal_id'         => $user->personal_id,
            'phone'               => $user->phone,
            'unit_id'             => $user->unit_id === null ? null : (int) $user->unit_id,
            'unit_name'           => $user->unit?->displayName(),
            'roles'               => $user->roles->map(fn ($r): array => ['id' => (int) $r->id, 'name' => $r->name])->values()->all(),
            // true = role data has been exported to the mobile application.
            'sent_to_application' => $exported,
        ];
    }

    /** @return array<int, string> unit id => display name */
    private function unitNames(): array
    {
        return Unit::query()->get()->mapWithKeys(fn (Unit $u): array => [$u->id => $u->displayName()])->all();
    }

    /** The start of a day (UTC, as stored in the DB). */
    private function dayStart(string $day, int $plusDays = 0): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC')
            ->addDays($plusDays)
            ->format('Y-m-d H:i:s');
    }
}

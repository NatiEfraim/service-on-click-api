<?php

namespace App\Services;

use App\Domain\Config\FormFieldRules;
use App\Domain\Workflow\Workflow;
use App\Enums\BookingStageEnum;
use App\Enums\StatusBookingEnum;
use App\Http\Resources\BookingResource;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\FormField;
use App\Models\SlaTarget;
use App\Models\Status;
use App\Models\SyncConflict;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;

/** The bookings a manager sees: the list and one booking in full, limited by AccessScope; the row shape is the contract in docs/admin-api.md. */
class BookingQueryService
{
    /** Columns the list can be sorted by in SQL. */
    private const SQL_SORT = ['serial_number', 'status_changed_at', 'created_at', 'status_id', 'booking_type_id'];

    private const MAX_PER_PAGE = 100;

    /** @var ?array<int, float> handling target hours by booking type id */
    private ?array $targets = null;

    /**
     * Filters (all optional): q (serial, ID number, personal number or name), statuses, types, units (comma-separated ids), stage,
     * from / to (Y-m-d, day opened), open_only=1, ids (serial numbers), handler (user uuid), mine=1, overdue=1,
     * sync (conflict | pending | synced), sort, dir, page, per_page.
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int, page: int, last_page: int, per_page: int}}
     */
    public function list(AccessScope $scope, array $filters): array
    {
        $query = $scope->applyToBookings(Booking::query())
            ->with(['user:id,uuid,full_name,personal_id,personal_number', 'bookingType:id,display_name', 'status:id,display_name'])
            ->withCount(['files', 'images']);

        if (filled($filters['q'] ?? null)) {
            $term = trim((string) $filters['q']);
            $like = '%' . addcslashes($term, '%_\\') . '%';

            $query->where(fn (Builder $where) => $where
                ->where('serial_number', 'like', $like)
                ->orWhereHas('user', fn (Builder $user) => $user
                    ->where('personal_id', 'like', addcslashes($term, '%_\\') . '%')
                    ->orWhere('personal_number', 'like', addcslashes($term, '%_\\') . '%')
                    ->orWhere('full_name', 'like', $like)));
        }

        foreach (['status_id' => 'statuses', 'booking_type_id' => 'types', 'unit_id' => 'units'] as $column => $key) {
            if ($ids = $this->ids($filters[$key] ?? null)) {
                $query->whereIn($column, $ids);
            }
        }

        if (filled($filters['stage'] ?? null)) {
            $this->limitToStage($query, $scope->workflow(), BookingStageEnum::tryFrom((string) $filters['stage']));
        }

        if (filled($filters['from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (($filters['open_only'] ?? '') === '1') {
            $query->whereNotIn('status_id', StatusBookingEnum::terminalIds());
        }

        if ($serials = $this->ids($filters['ids'] ?? null)) {
            $query->whereIn('serial_number', $serials);
        }

        if (filled($filters['handler'] ?? null)) {
            $query->whereHas('statusHistory', fn (Builder $history) => $history->whereHas('user', fn (Builder $user) => $user->where('uuid', $filters['handler'])));
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), self::MAX_PER_PAGE));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = (string) ($filters['sort'] ?? 'status_changed_at');

        if ($sort === 'stage_age_hours') {
            // The longer in the stage, the earlier it was entered.
            $query->orderBy('status_changed_at', $direction === 'desc' ? 'asc' : 'desc');
        } else {
            $query->orderBy(in_array($sort, self::SQL_SORT, true) ? $sort : 'status_changed_at', $direction);
        }

        $query->orderBy('id', 'desc');

        // Workflow-, target- and sync-dependent rules also become SQL conditions, so a page is one query.
        if (($filters['mine'] ?? '') === '1') {
            $this->limitToActionable($query, $scope);
        }

        if (($filters['overdue'] ?? '') === '1') {
            $this->limitToOverdue($query, true);
        }

        if (filled($filters['sync'] ?? null)) {
            $this->limitToSyncState($query, (string) $filters['sync']);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $conflicts = $this->openConflictIds(array_map(fn (Booking $booking): int => $booking->id, $paginator->items()));

        return [
            'data' => array_map(fn (Booking $booking): array => $this->row($booking, $scope, $conflicts), $paginator->items()),
            'meta' => ['total' => $paginator->total(), 'page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $perPage],
        ];
    }

    /** One booking in full, or null when it does not exist or the user may not see it. @return ?array<string, mixed> */
    public function show(AccessScope $scope, int $serial): ?array
    {
        $booking = Booking::query()
            ->with($this->detailRelations())
            ->where('serial_number', $serial)
            ->first();

        if ($booking === null || !$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, $booking->positionStatus()->value)) {
            return null;
        }

        $display = (new BookingResource($booking))->resolve();
        $conflicts = $this->openConflictIds([$booking->id]);
        $openConflict = isset($conflicts[$booking->id]) ? SyncConflict::query()->where('booking_id', $booking->id)->where('state', SyncConflict::STATE_OPEN)->first() : null;
        $statusLabels = Status::query()->pluck('display_name', 'id');

        return [
            ...$this->row($booking, $scope, $conflicts),
            'booking_owner' => $display['booking_owner'] ?? null,
            'vice_approver' => $display['vice_approver'] ?? null,
            'booking_values' => $display['booking_values'] ?? [],
            'comments' => $display['comments'] ?? [],
            'attachments' => app(AttachmentService::class)->describe($booking),
            'history' => $booking->applicationLog->map(fn ($log): array => [
                'action_type_id' => $log->action_type_id,
                'action_label' => $log->actionType?->display_type,
                'status_id' => $log->status_id,
                'status_label' => $log->status_id !== null ? ($statusLabels[$log->status_id] ?? null) : null,
                'actor_name' => $log->user?->full_name,
                'actor_id' => $log->user?->actorId(),
                'source' => $log->source,
                'occurred_at' => $log->created_at?->toIso8601String(),
                'correlation_id' => $log->correlation_id,
            ])->values()->all(),
            'can_edit_values' => $scope->workflow()->canEditValues($booking->positionStatus(), $scope->actingPermissions, (int) $booking->booking_type_id),
            'editable_fields' => FormField::query()
                ->where('unit_id', $booking->unit_id)
                ->where('booking_type_id', $booking->booking_type_id)
                ->where('editable', true)
                ->where('is_active', true)
                ->whereIn('field_type_id', FormFieldRules::EDITABLE_TYPES)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'field_display_name', 'field_type_id'])
                ->map(fn (FormField $field): array => ['form_field_id' => $field->id, 'field_display_name' => $field->field_display_name, 'field_type_id' => (int) $field->field_type_id])
                ->all(),
            'sync' => [
                'pending_export' => !$booking->is_status_exported,
                'ext_status_id' => $booking->ext_status_id,
                'ext_status_label' => $booking->ext_status_id ? ($statusLabels[$booking->ext_status_id] ?? null) : null,
                'open_conflict' => $openConflict ? [
                    'id' => $openConflict->id,
                    'internal_status_id' => $openConflict->internal_status_id,
                    'internal_status_label' => $statusLabels[$openConflict->internal_status_id] ?? null,
                    'external_status_id' => $openConflict->external_status_id,
                    'external_status_label' => $statusLabels[$openConflict->external_status_id] ?? null,
                    'detected_at' => $openConflict->created_at?->toIso8601String(),
                ] : null,
            ],
            'audit' => $scope->can('audit.view')
                ? AuditLog::query()->where('booking_serial', $serial)->orderBy('id')
                    ->get(['occurred_at', 'actor_id', 'actor_name', 'source', 'action', 'before', 'after', 'reason', 'result', 'correlation_id'])
                    ->all()
                : null,
        ];
    }

    /**
     * One row of the list.
     * @param array<int, mixed> $openConflictIds booking id => true for bookings with an open sync conflict
     * @return array<string, mixed>
     */
    public function row(Booking $booking, AccessScope $scope, array $openConflictIds = []): array
    {
        $status = $booking->statusEnum();
        $bookingTypeId = (int) $booking->booking_type_id;
        // Whose turn it is: a booking on hold stays at the stage it was put on hold from, whatever the hold says.
        $position = $booking->positionStatus();
        $stage = $scope->workflow()->stageOf($position, $bookingTypeId);
        $closed = $stage === BookingStageEnum::CLOSED;

        $stageAgeHours = now()->diffInMinutes($booking->statusSince(), true) / 60;
        $totalAgeHours = now()->diffInMinutes($booking->openedAt(), true) / 60;
        $target = $closed ? null : ($this->targets()[$bookingTypeId] ?? null);
        $hasConflict = isset($openConflictIds[$booking->id]);

        return [
            'uuid' => $booking->uuid,
            'serial_number' => $booking->serial_number,
            'booking_type_id' => $bookingTypeId,
            'booking_type_label' => $booking->bookingType?->display_name,
            'unit_id' => $booking->unit_id,
            'owner_uuid' => $booking->user?->uuid,
            'owner_name' => $booking->user?->full_name,
            'owner_personal_id' => $booking->user?->personal_id,
            'status_id' => $status->value,
            'status_label' => $booking->status?->display_name,
            'stage' => $stage->value,
            'stage_label' => $stage->label(),
            'stage_age_hours' => round($stageAgeHours, 1),
            'total_age_hours' => round($totalAgeHours, 1),
            'target_hours' => $target,
            'overdue' => $target !== null && $totalAgeHours > $target,
            'status_source' => $booking->status_source,
            'version' => (int) $booking->version,
            // List paper clip: file and image count (bytes may not have arrived yet; the detail says which).
            'attachments_count' => $booking->relationLoaded('files') && $booking->relationLoaded('images')
                ? $booking->files->count() + $booking->images->count()
                : (int) ($booking->files_count ?? 0) + (int) ($booking->images_count ?? 0),
            'opened_at' => $booking->openedAt()->toIso8601String(),
            'status_changed_at' => $booking->statusSince()->toIso8601String(),
            'sync_state' => $hasConflict ? 'conflict' : ($booking->is_status_exported ? 'synced' : 'pending'),
            'has_conflict' => $hasConflict,
            'actions' => $closed ? [] : array_map(fn (array $action): array => [
                'status_id' => $action['status']->value,
                'label' => $action['label'],
                'comment_required' => Workflow::validateComment($action['status'], '') !== null,
            ], $scope->workflow()->availableActions($position, $scope->actingPermissions, $bookingTypeId, $booking->holdStatus())),
        ];
    }

    /** Bookings the user can act on now: open and at a position where their role has a move (a booking on hold is where it was put on hold from). */
    public function limitToActionable(Builder $query, AccessScope $scope): void
    {
        $query->whereNotIn('status_id', StatusBookingEnum::terminalIds())->where(function (Builder $where) use ($scope): void {
            $where->whereRaw('1 = 0');

            foreach ($scope->actionableStatusesByType() as $bookingTypeId => $statusIds) {
                $where->orWhere(fn (Builder $type) => $type->where('booking_type_id', $bookingTypeId)->atPosition($statusIds));
            }
        });
    }

    /** Overdue bookings (open, older than their type's handling target) or, with false, all the others. */
    public function limitToOverdue(Builder $query, bool $overdue): void
    {
        $terminal = StatusBookingEnum::terminalIds();

        $isOverdue = function (Builder $where) use ($terminal): void {
            $where->whereRaw('1 = 0');

            foreach ($this->targets() as $bookingTypeId => $hours) {
                $where->orWhere(fn (Builder $type) => $type
                    ->where('booking_type_id', $bookingTypeId)
                    ->whereNotIn('status_id', $terminal)
                    ->where('created_at', '<', now()->subSeconds((int) round($hours * 3600))));
            }
        };

        $overdue ? $query->where($isOverdue) : $query->whereNot($isOverdue);
    }

    /** Open bookings at least this old, counted from the day they were opened. */
    public function limitToMinimumAge(Builder $query, float $days): void
    {
        $query->whereNotIn('status_id', StatusBookingEnum::terminalIds())->where('created_at', '<=', now()->subSeconds((int) round($days * 86400)));
    }

    /** Bookings whose decision is in this state toward the mobile app: conflict, pending (not sent yet) or synced. */
    public function limitToSyncState(Builder $query, string $state): void
    {
        $openConflict = fn (Builder $conflicts) => $conflicts->where('state', SyncConflict::STATE_OPEN);

        match ($state) {
            'conflict' => $query->whereHas('syncConflicts', $openConflict),
            'pending' => $query->where('is_status_exported', false)->whereDoesntHave('syncConflicts', $openConflict),
            'synced' => $query->where('is_status_exported', true)->whereDoesntHave('syncConflicts', $openConflict),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Bookings in the given stage (some stages, e.g. control officer, exist only for some types); a booking on hold is in the stage it was put on hold from. */
    public function limitToStage(Builder $query, Workflow $workflow, ?BookingStageEnum $stage): void
    {
        if ($stage === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $where) use ($workflow, $stage): void {
            $where->whereRaw('1 = 0');

            foreach (BookingType::query()->pluck('id') as $bookingTypeId) {
                $statusIds = [];

                foreach (StatusBookingEnum::cases() as $status) {
                    if ($workflow->stageOf($status, (int) $bookingTypeId) === $stage) {
                        $statusIds[] = $status->value;
                    }
                }

                if ($statusIds !== []) {
                    $where->orWhere(fn (Builder $type) => $type->where('booking_type_id', $bookingTypeId)->atPosition($statusIds));
                }
            }
        });
    }

    /** @return array<int, int> ids from a comma-separated string or a list */
    private function ids(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('intval', $list), fn (int $id): bool => $id > 0));
    }

    /** @param array<int, int> $bookingIds @return array<int, true> booking id => true */
    private function openConflictIds(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        return SyncConflict::query()
            ->whereIn('booking_id', $bookingIds)
            ->where('state', SyncConflict::STATE_OPEN)
            ->pluck('booking_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /** @return array<int, float> handling target hours by booking type id */
    private function targets(): array
    {
        return $this->targets ??= SlaTarget::query()
            ->where('stage', SlaTarget::STAGE_TOTAL)
            ->get(['booking_type_id', 'target_hours'])
            ->mapWithKeys(fn (SlaTarget $target): array => [(int) $target->booking_type_id => (float) $target->target_hours])
            ->all();
    }

    /** Relations the detail view loads (those of the approver's list for BookingResource) plus the timeline. @return array<int, string> */
    private function detailRelations(): array
    {
        return [
            'bookingType',
            'status',
            'user',
            'comments.user',
            'viceApprovers.user',
            'files',
            'images',
            'applicationLog' => fn ($logs) => $logs->orderBy('created_at')->orderBy('id'),
            'applicationLog.actionType',
            'applicationLog.user',
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
        ];
    }
}

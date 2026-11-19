<?php

namespace App\Services;

use App\Domain\Config\FormFieldRules;
use App\Domain\Workflow\Transition;
use App\Domain\Workflow\Workflow;
use App\Enums\BookingStageEnum;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormSectionEnum;
use App\Enums\StatusBookingEnum;
use App\Http\Resources\BookingResource;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\SlaTarget;
use App\Models\Status;
use App\Models\SyncConflict;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** The bookings a manager sees: the list and one booking in full, limited by AccessScope; the row shape is the contract in docs/admin-api.md. */
class BookingQueryService
{
    /** Columns the list can be sorted by in SQL. */
    private const SQL_SORT = ['serial_number', 'status_changed_at', 'created_at', 'status_id', 'booking_type_id', 'stage_due_at'];

    private const MAX_PER_PAGE = 100;

    /** @var ?array<int, float> handling target hours by booking type id */
    private ?array $targets = null;

    /** @var array<string, array<string, int>> "type:unit" => handler field name => id */
    private array $handlerFieldIds = [];

    /** @var ?array<int, int> ids of the fields that hold a candidate's ID / personal number on route types (for the text search) */
    private ?array $personFieldIds = null;

    /** @param array<string, mixed> $filters q, statuses, types, units, stage, from/to, open_only, ids, uuids, handler, assigned_to, mine, overdue, sync, sort, dir, page, per_page @return array{data: array<int, array<string, mixed>>, meta: array{total: int, page: int, last_page: int, per_page: int}} */
    public function list(AccessScope $scope, array $filters): array
    {
        $query = $scope->applyToBookings(Booking::query())
            ->with(['user:id,uuid,full_name,personal_id,personal_number', 'bookingType:id,display_name', 'status:id,display_name', 'handler:id,uuid,full_name'])
            ->withCount(['files', 'images']);

        if (filled($filters['q'] ?? null)) {
            $term = trim((string) $filters['q']);
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $personFieldIds = $this->personFieldIds();

            $query->where(fn (Builder $where) => $where
                ->where('serial_number', 'like', $like)
                ->orWhereHas('user', fn (Builder $user) => $user
                    ->where('personal_id', 'like', addcslashes($term, '%_\\') . '%')
                    ->orWhere('personal_number', 'like', addcslashes($term, '%_\\') . '%')
                    ->orWhere('full_name', 'like', $like))
                // Route types: the candidate's ID / personal number as typed in the form.
                ->when($personFieldIds !== [], fn (Builder $q) => $q->orWhereHas('bookingValue', fn (Builder $values) => $values
                    ->whereIn('form_field_id', $personFieldIds)
                    ->whereRaw('CAST(value AS CHAR) LIKE ?', [$like]))));
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

        if ($uuids = $this->uuids($filters['uuids'] ?? null)) {
            $query->whereIn('uuid', $uuids);
        }

        if (filled($filters['handler'] ?? null)) {
            $query->whereHas('statusHistory', fn (Builder $history) => $history->whereHas('user', fn (Builder $user) => $user->where('uuid', $filters['handler'])));
        }

        // The assigned handler of a route type: me, none, or a person's uuid.
        if (filled($filters['assigned_to'] ?? null)) {
            $assignedTo = (string) $filters['assigned_to'];

            match (true) {
                $assignedTo === 'me' => $query->where('handler_user_id', $scope->user->id),
                $assignedTo === 'none' => $query->whereNull('handler_user_id'),
                Str::isUuid($assignedTo) => $query->whereHas('handler', fn (Builder $handler) => $handler->where('uuid', $assignedTo)),
                default => $query->whereRaw('1 = 0'),
            };
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
        $bookingTypeId = (int) $booking->booking_type_id;
        $route = $scope->workflow()->routeFor($bookingTypeId);
        $assignment = $route?->assignment();
        $inAssignmentStage = $assignment !== null && in_array((int) $booking->status_id, $assignment['statuses'], true);

        return [
            ...$this->row($booking, $scope, $conflicts),
            'booking_owner' => $display['booking_owner'] ?? null,
            'vice_approver' => $display['vice_approver'] ?? null,
            'booking_values' => $display['booking_values'] ?? [],
            'comments' => $display['comments'] ?? [],
            'attachments' => app(AttachmentService::class)->describe($booking),
            'history' => $booking->applicationLog->map(fn ($log): array => [
                'action_label' => $log->actionType?->display_type,
                'status_id' => $log->status_id,
                'status_label' => $log->status_id !== null ? ($statusLabels[$log->status_id] ?? null) : null,
                'actor_name' => $log->user?->full_name,
                'actor_id' => $log->user?->actorId(),
                'source' => $log->source,
                'occurred_at' => $log->created_at?->toIso8601String(),
                'correlation_id' => $log->correlation_id,
            ])->values()->all(),
            // Route types: the handler and the section the handlers fill.
            'handler' => $booking->handler === null ? null : [
                'uuid' => $booking->handler->uuid,
                'full_name' => $booking->handler->full_name,
                'assigned_at' => $booking->handler_assigned_at?->toIso8601String(),
            ],
            'can_assign_handler' => $route !== null && $inAssignmentStage
                && $scope->workflow()->holdsRouteRole($scope->actingPermissions, $assignment['assigners'], $bookingTypeId),
            'can_take' => $route !== null && $inAssignmentStage && $booking->handler_user_id === null
                && $scope->workflow()->holdsRouteRole($scope->actingPermissions, $assignment['self'], $bookingTypeId),
            'progress' => app(ProgressService::class)->progress($booking),
            'handler_fields' => $route === null ? [] : $this->handlerFields($booking, $scope),
            'sync' => [
                'ext_status_id' => $booking->ext_status_id,
                'ext_status_label' => $booking->ext_status_id ? ($statusLabels[$booking->ext_status_id] ?? null) : null,
                // The screen needs only which conflict to settle; the sync screen lists the conflicts with their statuses.
                'open_conflict' => $openConflict ? ['id' => $openConflict->id] : null,
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
        $webOnly = BookingTypeEnum::isWebOnlyId($bookingTypeId);

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
            // Route types: the assigned handler and the moment the scheduler waits for (the interview).
            'handler_uuid' => $booking->relationLoaded('handler') ? $booking->handler?->uuid : null,
            'handler_name' => $booking->relationLoaded('handler') ? $booking->handler?->full_name : null,
            'stage_due_at' => $booking->stage_due_at?->toIso8601String(),
            // A web-only type has no mobile side to be in sync with.
            'sync_state' => $webOnly ? null : ($hasConflict ? 'conflict' : ($booking->is_status_exported ? 'synced' : 'pending')),
            'has_conflict' => $hasConflict,
            'actions' => $closed ? [] : $this->actions($booking, $scope, $position, $bookingTypeId),
        ];
    }

    /**
     * The buttons this user may press now. Every entry carries the keys the screens read (status_id, label, comment_required); a route
     * move adds what it needs (handler, fields) and whether it is enabled right now.
     * @return array<int, array<string, mixed>>
     */
    private function actions(Booking $booking, AccessScope $scope, StatusBookingEnum $position, int $bookingTypeId): array
    {
        $fieldIds = $scope->workflow()->hasRoute($bookingTypeId) ? $this->handlerFieldIds($bookingTypeId, (int) $booking->unit_id) : [];
        $rows = [];

        foreach ($scope->workflow()->availableActions($position, $scope->actingPermissions, $bookingTypeId, $booking->holdStatus()) as $action) {
            /** @var ?Transition $transition */
            $transition = $action['transition'] ?? null;
            $enabled = true;
            $disabledReason = null;

            if ($transition !== null && $transition->requiresHandler && $booking->handler_user_id === null) {
                $enabled = false;
                $disabledReason = 'יש להקצות מטפל לפני קביעת מועד התחקיר';
            }

            $rows[] = [
                'status_id' => $action['status']->value,
                'label' => $action['label'],
                'comment_required' => Workflow::validateComment($action['status'], '', $transition?->commentRequired) !== null,
                'direction' => $transition?->direction ?? $this->genericDirection($action['status']),
                'required_fields' => $transition === null ? [] : array_values(array_filter(array_map(fn (string $name): ?int => $fieldIds[$name] ?? null, $transition->requiredFields))),
                'clears_fields' => $transition === null ? [] : array_values(array_filter(array_map(fn (string $name): ?int => $fieldIds[$name] ?? null, $transition->clearsFields))),
                'enabled' => $enabled,
                'disabled_reason' => $disabledReason,
            ];
        }

        return $rows;
    }

    /** The direction of a generic move: the final rejection, a closing status, or a step forward (holds and recommendations included). */
    private function genericDirection(StatusBookingEnum $target): string
    {
        if ($target === StatusBookingEnum::REJECTED) {
            return Transition::DIRECTION_REJECT;
        }

        return $target->isTerminal() ? Transition::DIRECTION_CLOSE : Transition::DIRECTION_FORWARD;
    }

    /**
     * The handler section of a route type's request: every handler field with its current value in INPUT format (a date as Y-m-d, a time as
     * H:i - unlike booking_values, which is display-formatted) and whether this user may write it now.
     * @return array<int, array<string, mixed>>
     */
    private function handlerFields(Booking $booking, AccessScope $scope): array
    {
        $fields = FormField::query()
            ->where('booking_type_id', $booking->booking_type_id)
            ->where('unit_id', $booking->unit_id)
            ->section(FormSectionEnum::HANDLER)
            ->where('is_active', true)
            ->with('fieldType')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $values = BookingValue::query()
            ->where('booking_id', $booking->id)
            ->whereIn('form_field_id', $fields->modelKeys())
            ->get()
            ->keyBy('form_field_id');

        $canEdit = $scope->workflow()->canEditValues($booking->positionStatus(), $scope->actingPermissions, (int) $booking->booking_type_id);

        return $fields->map(function (FormField $field) use ($values, $canEdit): array {
            $row = $values->get($field->id);
            $value = $row?->value;

            if (is_array($value) && array_is_list($value) && count($value) === 1) {
                $value = $value[0];
            }

            return [
                'form_field_id' => $field->id,
                'field_name' => $field->field_name,
                'field_display_name' => $field->field_display_name,
                'field_type' => ['id' => $field->fieldType?->id, 'name' => $field->fieldType?->name],
                'col_span' => (int) ($field->col_span ?? 1),
                'position' => (int) $field->position,
                'is_required' => (bool) $field->is_required,
                'validations' => $field->normalizedValidations(),
                'value' => $row === null ? null : ($row->option_field_id !== null ? (int) $row->option_field_id : (is_scalar($value) ? (string) $value : null)),
                'can_edit' => $canEdit && in_array((int) $field->field_type_id, FormFieldRules::HANDLER_EDITABLE_TYPES, true),
            ];
        })->values()->all();
    }

    /** @return array<string, int> handler field name => id for the type in the unit (cached per request) */
    private function handlerFieldIds(int $bookingTypeId, int $unitId): array
    {
        $key = "{$bookingTypeId}:{$unitId}";

        return $this->handlerFieldIds[$key] ??= FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', $unitId)
            ->section(FormSectionEnum::HANDLER)
            ->where('is_active', true)
            ->pluck('id', 'field_name')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array<int, int> the ids of the personal_id / personal_number fields of the route types (text search) */
    private function personFieldIds(): array
    {
        if ($this->personFieldIds !== null) {
            return $this->personFieldIds;
        }

        $routeTypeIds = app(Workflow::class)->routeTypeIds();

        return $this->personFieldIds = $routeTypeIds === [] ? [] : FormField::query()
            ->whereIn('booking_type_id', $routeTypeIds)
            ->whereIn('field_name', ['personal_id', 'personal_number'])
            ->whereIn('field_type_id', [FieldTypeEnum::TEXT->value, FieldTypeEnum::NUMBER->value])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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

    /** The valid uuids of a comma list or array; anything that is not a uuid is dropped. @return array<int, string> */
    private function uuids(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map(fn ($uuid): string => trim((string) $uuid), $list),
            fn (string $uuid): bool => Str::isUuid($uuid),
        ));
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

    /** Relations the detail view loads (those of the approver's list for BookingResource) plus the timeline and the handler. @return array<int|string, mixed> */
    private function detailRelations(): array
    {
        return [
            'bookingType',
            'status',
            'user',
            'handler:id,uuid,full_name',
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

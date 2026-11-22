<?php

namespace App\Services;

use App\Domain\Workflow\Transition;
use App\Domain\Workflow\TypeRoute;
use App\Domain\Workflow\Workflow;
use App\Enums\ActionTypeEnum;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\FormSectionEnum;
use App\Enums\StatusBookingEnum;
use App\Enums\SystemReferenceEnum;
use App\Exceptions\DomainError;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\Comment;
use App\Models\FormField;
use App\Models\Status;
use App\Models\SyncConflict;
use App\Models\SystemReference;
use App\Models\User;
use App\Models\ViceApproverHasBooking;
use App\Support\AccessScope;
use App\Support\DueMoment;
use App\Support\FieldValueValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates and applies a status change by a person: checks visibility, Workflow permission, comment rules, deadline, no open conflict and version.
 * BookingStatusExportService sends the decision to the mobile app (not for a web-only type). On a booking type with a route of its own the move
 * may also fill handler fields (the interview place, date and time), need an assigned handler, clear fields on a move back, and set the moment
 * the scheduler waits for.
 */
class BookingWorkflowService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BookingStatusRecorder $recorder,
    ) {
    }

    /**
     * @param ?string $idempotencyKey the client's Idempotency-Key: a repeated request with the same key gets the first answer
     * @param array<int|string, mixed> $values route types: the handler fields the move fills (form_field_id => value)
     * @return array{serial_number: int, status_id: int, status_label: ?string, version: int, correlation_id: string, sync_state: ?string, message: string}
     */
    public function changeStatus(AccessScope $scope, int $serial, int $targetStatusId, ?string $comment, int $expectedVersion, ?string $idempotencyKey = null, array $values = []): array
    {
        $user = $scope->user;
        $comment = trim((string) $comment);
        $values = $this->cleanValues($values);
        $requestHash = hash('sha256', json_encode([$serial, $targetStatusId, $expectedVersion, $comment, $values], JSON_UNESCAPED_UNICODE));

        if ($idempotencyKey !== null && ($replay = $this->replay($user, $idempotencyKey, $requestHash)) !== null) {
            return $replay;
        }

        $target = StatusBookingEnum::tryFrom($targetStatusId) ?? throw new DomainError('סטטוס יעד אינו תקין', 422);

        // Unlocked checks run before the transaction so a refusal's audit row is not rolled back.
        $this->assertMayChange($scope, Booking::query()->where('serial_number', $serial)->first(), $serial, $target, $comment, $expectedVersion, $values, audit: true);

        return DB::transaction(function () use ($scope, $user, $serial, $target, $comment, $expectedVersion, $idempotencyKey, $requestHash, $values): array {
            $booking = Booking::query()->where('serial_number', $serial)->lockForUpdate()->first();

            // Another request with the same key may have finished while this one waited for the lock.
            if ($idempotencyKey !== null && ($replay = $this->replay($user, $idempotencyKey, $requestHash)) !== null) {
                return $replay;
            }

            // The booking may have moved on since the check above.
            $transition = $this->assertMayChange($scope, $booking, $serial, $target, $comment, $expectedVersion, $values, audit: false);

            if (SyncConflict::query()->where('booking_id', $booking->id)->where('state', SyncConflict::STATE_OPEN)->exists()) {
                throw new DomainError('קיימת התנגשות סנכרון פתוחה לקריאה זו. יש ליישב אותה לפני פעולה נוספת.', 409);
            }

            $bookingTypeId = (int) $booking->booking_type_id;
            $route = $scope->workflow()->routeFor($bookingTypeId);
            $webOnly = BookingTypeEnum::isWebOnlyId($bookingTypeId);
            $current = $booking->statusEnum();
            $before = ['status_id' => $current->value, 'version' => (int) $booking->version];
            $valueChanges = null;

            if ($route !== null && $transition !== null) {
                // "Take it": the one who presses becomes the handler when nobody is; "back to the queue": nobody is. Written in the
                // same update as the status, so one version bump covers both.
                $assignmentChange = [];

                if ($transition->assignsActor && $booking->handler_user_id === null) {
                    $assignmentChange = ['handler_user_id' => $user->id, 'handler_assigned_at' => now()];
                } elseif ($transition->releasesHandler && $booking->handler_user_id !== null) {
                    $assignmentChange = ['handler_user_id' => null, 'handler_assigned_at' => null];
                }

                $handlerFields = $this->handlerFields($booking);
                $written = $this->writeHandlerValues($booking, $handlerFields, $values);
                $cleared = $this->clearHandlerValues($booking, $handlerFields, $transition->clearsFields);
                $dueAt = DueMoment::for($route, $target->value, $this->handlerValuesByName($booking, $handlerFields));

                $correlationId = $this->recorder->apply(
                    $booking,
                    $target,
                    $user,
                    'internal',
                    null,
                    $this->actionTypeFor($transition),
                    ['stage_due_at' => $dueAt] + $assignmentChange,
                );

                if ($written !== [] || $cleared !== []) {
                    $valueChanges = ['before' => $cleared, 'after' => $written];
                }
            } else {
                $correlationId = $this->recorder->apply($booking, $target, $user);
            }

            if ($comment !== '') {
                Comment::query()->create(['booking_id' => $booking->id, 'user_id' => $user->id, 'comment' => $comment]);
            }

            if ($route === null) {
                $this->linkViceApprover($booking, $target, $user);
            }

            $this->audit->record([
                'action' => 'booking.status_change',
                'entity_type' => 'booking',
                'entity_id' => $serial,
                'booking_serial' => $serial,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
                'before' => $before + ($valueChanges !== null ? ['values' => $valueChanges['before']] : []),
                // A hold does not move the booking: the row says where it stays.
                'after' => ['status_id' => $target->value, 'version' => (int) $booking->version]
                    + ($booking->parked_status_id !== null ? ['parked_status_id' => (int) $booking->parked_status_id] : [])
                    + ($valueChanges !== null ? ['values' => $valueChanges['after']] : []),
                'reason' => $comment !== '' ? $comment : null,
                'correlation_id' => $correlationId,
            ], $user);

            $result = [
                'serial_number' => $serial,
                'status_id' => $target->value,
                'status_label' => Status::query()->whereKey($target->value)->value('display_name'),
                'version' => (int) $booking->version,
                'correlation_id' => $correlationId,
                // A web-only type has no mobile side to sync with.
                'sync_state' => $webOnly ? null : 'pending',
                'message' => $webOnly ? 'הסטטוס עודכן.' : 'הסטטוס עודכן. ההחלטה תועבר לאפליקציה בסבב הסנכרון הבא.',
            ];

            if ($idempotencyKey !== null) {
                DB::table('idempotency_keys')->insert([
                    'user_id' => $user->id,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $result;
        });
    }

    /**
     * Checks ownership, version, stage visibility, permission, requester-only, comment, deadline and (route types) the move's own needs;
     * a stale version answers 409. Returns the route transition when the type has a route.
     * @param array<int|string, mixed> $values @param bool $audit log a permission refusal
     */
    private function assertMayChange(AccessScope $scope, ?Booking $booking, int $serial, StatusBookingEnum $target, string $comment, int $expectedVersion, array $values, bool $audit): ?Transition
    {
        $user = $scope->user;

        if ($booking === null || !$scope->managesBooking($booking->unit_id, (int) $booking->booking_type_id)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'not_visible', $user, ['booking_serial' => $serial]);

            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        if ((int) $booking->version !== $expectedVersion) {
            throw new DomainError('הקריאה עודכנה בינתיים (ייתכן שבאפליקציה). יש לרענן ולבדוק את המצב העדכני.', 409);
        }

        // Whose turn it is follows the position: a booking on hold stays with the stage it was put on hold from.
        if (!$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, $booking->positionStatus()->value)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'not_visible', $user, ['booking_serial' => $serial]);

            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        $context = ['booking_serial' => $serial, 'unit_id' => $booking->unit_id, 'booking_type_id' => $booking->booking_type_id];
        $current = $booking->statusEnum();
        $bookingTypeId = (int) $booking->booking_type_id;

        if (Workflow::isRequesterOnly($target)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'requester_only', $user, $context);

            throw new DomainError('ביטול על ידי המזמין מתבצע באפליקציה בלבד', 403);
        }

        if (!$scope->workflow()->canChangeTo($booking->positionStatus(), $target, $scope->actingPermissions, $bookingTypeId, $booking->holdStatus())) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, "transition {$current->value}->{$target->value} not allowed", $user, $context);

            throw new DomainError('הפעולה אינה זמינה עבורך בשלב הנוכחי של הקריאה', 403);
        }

        $transition = $scope->workflow()->transitionFor($bookingTypeId, $booking->positionStatus(), $target);

        if ($error = Workflow::validateComment($target, $comment, $transition?->commentRequired)) {
            throw new DomainError($error, 422);
        }

        $this->assertCancellationDeadline($booking, $target);

        if ($transition !== null) {
            $this->assertRouteMove($scope->workflow()->routeFor($bookingTypeId), $booking, $transition, $values);
        }

        return $transition;
    }

    /**
     * What a route move needs: an assigned handler when it says so, only handler fields in the values and each of them valid, every field the
     * move requires either sent now or already stored, and the due moment it sets (the interview) still ahead.
     * @param array<int|string, mixed> $values
     */
    private function assertRouteMove(TypeRoute $route, Booking $booking, Transition $transition, array $values): void
    {
        if ($transition->requiresHandler && !$transition->assignsActor && $booking->handler_user_id === null) {
            throw new DomainError('יש להקצות מטפל לפני קביעת מועד התחקיר', 422);
        }

        $handlerFields = $this->handlerFields($booking);
        $errors = [];

        foreach ($values as $fieldId => $value) {
            $field = $handlerFields->get((int) $fieldId);

            if ($field === null) {
                $errors["values.{$fieldId}"][] = 'ניתן לעדכן רק את השדות שממלאים הגורמים המטפלים';
                continue;
            }

            foreach (FieldValueValidator::errorsFor($field, $value) as $error) {
                $errors["values.{$fieldId}"][] = $error;
            }
        }

        $stored = $this->handlerValuesByName($booking, $handlerFields);
        $merged = $stored;

        foreach ($values as $fieldId => $value) {
            if (($field = $handlerFields->get((int) $fieldId)) !== null) {
                $merged[$field->field_name] = $value;
            }
        }

        foreach ($transition->requiredFields as $fieldName) {
            $field = $handlerFields->first(fn (FormField $candidate): bool => $candidate->field_name === $fieldName);

            if ($field !== null && trim((string) ($merged[$fieldName] ?? '')) === '') {
                $errors["values.{$field->id}"][] = FieldValueValidator::requiredMessage($field);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $dueAt = DueMoment::for($route, $transition->to, $merged);

        if ($dueAt !== null && $dueAt->lessThanOrEqualTo(now())) {
            throw new DomainError('מועד התחקיר חייב להיות עתידי', 422);
        }
    }

    /** The active handler fields of the booking's type and unit, keyed by id. @return Collection<int, FormField> */
    private function handlerFields(Booking $booking): Collection
    {
        return FormField::query()
            ->where('booking_type_id', $booking->booking_type_id)
            ->where('unit_id', $booking->unit_id)
            ->section(FormSectionEnum::HANDLER)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
    }

    /** The booking's stored handler values by field name (a scalar each). @param Collection<int, FormField> $handlerFields @return array<string, string> */
    private function handlerValuesByName(Booking $booking, Collection $handlerFields): array
    {
        if ($handlerFields->isEmpty()) {
            return [];
        }

        $rows = BookingValue::query()
            ->where('booking_id', $booking->id)
            ->whereIn('form_field_id', $handlerFields->keys()->all())
            ->whereNull('option_field_id')
            ->get(['form_field_id', 'value']);

        $byName = [];

        foreach ($rows as $row) {
            $value = $row->value;

            if (is_array($value) && array_is_list($value) && count($value) === 1) {
                $value = $value[0];
            }

            if (is_scalar($value) && (string) $value !== '') {
                $byName[$handlerFields->get((int) $row->form_field_id)->field_name] = (string) $value;
            }
        }

        return $byName;
    }

    /** Stores the handler values sent with the move. @param Collection<int, FormField> $handlerFields @return array<string, string> written, by field label */
    private function writeHandlerValues(Booking $booking, Collection $handlerFields, array $values): array
    {
        $written = [];

        foreach ($values as $fieldId => $value) {
            $field = $handlerFields->get((int) $fieldId);

            if ($field === null) {
                continue;
            }

            $isSelect = (int) $field->field_type_id === FieldTypeEnum::SELECT->value;

            BookingValue::query()->updateOrCreate(
                ['booking_id' => $booking->id, 'form_field_id' => $field->id],
                ['option_field_id' => $isSelect ? (int) $value : null, 'value' => $isSelect ? null : (string) $value],
            );

            $written[$field->field_display_name] = (string) $value;
        }

        return $written;
    }

    /** Deletes the handler values a move back clears. @param Collection<int, FormField> $handlerFields @param array<int, string> $fieldNames @return array<string, string> deleted, by field label */
    private function clearHandlerValues(Booking $booking, Collection $handlerFields, array $fieldNames): array
    {
        $fields = $handlerFields->filter(fn (FormField $field): bool => in_array($field->field_name, $fieldNames, true));

        if ($fields->isEmpty()) {
            return [];
        }

        $cleared = [];

        foreach (BookingValue::query()->where('booking_id', $booking->id)->whereIn('form_field_id', $fields->keys()->all())->get() as $row) {
            $value = $row->value;
            $cleared[$fields->get((int) $row->form_field_id)->field_display_name] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $row->delete();
        }

        return $cleared;
    }

    /** The timeline action of a route move: a move back, a refusal, or a step forward (the closing included). */
    private function actionTypeFor(Transition $transition): ActionTypeEnum
    {
        return match ($transition->direction) {
            Transition::DIRECTION_BACK => ActionTypeEnum::RETURN_STAGE,
            Transition::DIRECTION_REJECT => ActionTypeEnum::REJECT_BOOKING,
            default => ActionTypeEnum::ADVANCE_STAGE,
        };
    }

    /** Trims the values sent and drops the empty ones. @param array<int|string, mixed> $values @return array<int|string, string> */
    private function cleanValues(array $values): array
    {
        $clean = [];

        foreach ($values as $fieldId => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '' && ctype_digit((string) $fieldId)) {
                $clean[(int) $fieldId] = $value;
            }
        }

        ksort($clean);

        return $clean;
    }

    /** The earlier answer to the same request, or null for a new key. Throws when the key was used for another request. */
    private function replay(User $user, string $key, string $requestHash): ?array
    {
        $row = DB::table('idempotency_keys')->where('user_id', $user->id)->where('key', $key)->first();

        if ($row === null) {
            return null;
        }

        if ($row->request_hash !== $requestHash) {
            throw new DomainError('מפתח הפעולה כבר שימש לבקשה אחרת', 422);
        }

        return json_decode($row->response, true);
    }

    /** A food booking can be canceled only so many business days before its supply date (unit setting). */
    private function assertCancellationDeadline(Booking $booking, StatusBookingEnum $target): void
    {
        if ($target !== StatusBookingEnum::CANCELED_BY_APPROVER) {
            return;
        }

        $bookingTypeId = (int) $booking->booking_type_id;

        [$daysReference, $supplyDateField] = match (true) {
            $bookingTypeId === BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId()
                => [SystemReferenceEnum::IN_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS, FormFieldEnum::IN_UNIT_SUPPLY_DATE],
            $bookingTypeId === BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId()
                => [SystemReferenceEnum::OUT_UNIT_FOOD_CANCELLATION_ADVANCE_DAYS, FormFieldEnum::OUT_UNIT_SUPPLY_DATE],
            default => [null, null],
        };

        if ($daysReference === null) {
            return;
        }

        $days = (int) (SystemReference::query()
            ->where('unit_id', $booking->unit_id)
            ->where('booking_type_id', $bookingTypeId)
            ->where('name', $daysReference->getSystemReferenceName())
            ->first()?->decodedValue()['cancellation_advance_days'] ?? 0);

        $supplyDate = BookingValue::query()
            ->where('booking_id', $booking->id)
            ->where('form_field_id', $supplyDateField->getFormFieldId())
            ->whereNull('option_field_id')
            ->value('value');

        if ($days <= 0 || !is_string($supplyDate) || $supplyDate === '') {
            return;
        }

        try {
            $deadline = Workflow::lastAllowedCancellation(new \DateTimeImmutable($supplyDate), $days);
        } catch (\Exception) {
            return;
        }

        if (new \DateTimeImmutable() > $deadline) {
            throw new DomainError("לא ניתן לבטל הזמנה זו - יש לבטל לפחות {$days} ימי עסקים לפני תאריך האספקה", 422);
        }
    }

    /** Ties the vice approver who passed the booking on - or the barber who answered it (11, 12) - to it, so dashboards can filter by handler. */
    private function linkViceApprover(Booking $booking, StatusBookingEnum $target, User $user): void
    {
        $approved = match ($target) {
            StatusBookingEnum::VICE_APPROVER_APPROVED, StatusBookingEnum::APPROVED_BY_BARBER => true,
            StatusBookingEnum::VICE_APPROVER_REJECTED, StatusBookingEnum::CANCELED_BY_BARBER => false,
            default => null,
        };

        if ($approved === null) {
            return;
        }

        ViceApproverHasBooking::query()->updateOrCreate(
            ['booking_id' => $booking->id, 'user_id' => $user->id],
            ['approved_by_vice_approver' => $approved, 'is_deleted' => false],
        );
    }
}

<?php

namespace App\Services;

use App\Domain\Workflow\Workflow;
use App\Enums\BookingTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\StatusBookingEnum;
use App\Enums\SystemReferenceEnum;
use App\Exceptions\DomainError;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\Comment;
use App\Models\Status;
use App\Models\SyncConflict;
use App\Models\SystemReference;
use App\Models\User;
use App\Models\ViceApproverHasBooking;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * A status change made by a person here. Every rule is enforced on the server, never only in the UI: the booking is visible to the user, the
 * user's permission for the booking type allows this move at this stage (Workflow), the requester's own cancellation is left to the mobile
 * app, the comment rules, the food cancellation deadline, no open sync conflict, and the version the user saw is still the current one.
 * Business completion (the status is saved) is separate from transfer completion (the decision is sent back to the mobile app later -
 * see BookingStatusExportService).
 */
class BookingWorkflowService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BookingStatusRecorder $recorder,
    ) {
    }

    /**
     * @param ?string $idempotencyKey the client's Idempotency-Key: a repeated request with the same key gets the first answer instead of a second action
     * @return array{serial_number: int, status_id: int, status_label: ?string, version: int, correlation_id: string, sync_state: string, message: string}
     * @throws DomainError 404 not visible, 403 not allowed, 409 changed meanwhile or in conflict, 422 invalid
     */
    public function changeStatus(AccessScope $scope, int $serial, int $targetStatusId, ?string $comment, int $expectedVersion, ?string $idempotencyKey = null): array
    {
        $user = $scope->user;
        $comment = trim((string) $comment);
        $requestHash = hash('sha256', json_encode([$serial, $targetStatusId, $expectedVersion, $comment], JSON_UNESCAPED_UNICODE));

        if ($idempotencyKey !== null && ($replay = $this->replay($user, $idempotencyKey, $requestHash)) !== null) {
            return $replay;
        }

        $target = StatusBookingEnum::tryFrom($targetStatusId) ?? throw new DomainError('סטטוס יעד אינו תקין', 422);

        // The checks that need no lock come first, outside the transaction: a refusal is audited, and a row written inside a transaction that
        // the refusal rolls back would go with it.
        $this->assertMayChange($scope, Booking::query()->where('serial_number', $serial)->first(), $serial, $target, $comment, $expectedVersion, audit: true);

        return DB::transaction(function () use ($scope, $user, $serial, $target, $comment, $expectedVersion, $idempotencyKey, $requestHash): array {
            $booking = Booking::query()->where('serial_number', $serial)->lockForUpdate()->first();

            // Another request with the same key may have finished while this one waited for the lock.
            if ($idempotencyKey !== null && ($replay = $this->replay($user, $idempotencyKey, $requestHash)) !== null) {
                return $replay;
            }

            // The booking may have moved on since the check above.
            $this->assertMayChange($scope, $booking, $serial, $target, $comment, $expectedVersion, audit: false);

            if (SyncConflict::query()->where('booking_id', $booking->id)->where('state', SyncConflict::STATE_OPEN)->exists()) {
                throw new DomainError('קיימת התנגשות סנכרון פתוחה לקריאה זו. יש ליישב אותה לפני פעולה נוספת.', 409);
            }

            $current = $booking->statusEnum();
            $before = ['status_id' => $current->value, 'version' => (int) $booking->version];

            $correlationId = $this->recorder->apply($booking, $target, $user);

            if ($comment !== '') {
                Comment::query()->create(['booking_id' => $booking->id, 'user_id' => $user->id, 'comment' => $comment]);
            }

            $this->linkViceApprover($booking, $target, $user);

            $this->audit->record([
                'action' => 'booking.status_change',
                'entity_type' => 'booking',
                'entity_id' => $serial,
                'booking_serial' => $serial,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
                'before' => $before,
                'after' => ['status_id' => $target->value, 'version' => (int) $booking->version],
                'reason' => $comment !== '' ? $comment : null,
                'correlation_id' => $correlationId,
            ], $user);

            $result = [
                'serial_number' => $serial,
                'status_id' => $target->value,
                'status_label' => Status::query()->whereKey($target->value)->value('display_name'),
                'version' => (int) $booking->version,
                'correlation_id' => $correlationId,
                'sync_state' => 'pending',
                'message' => 'הסטטוס עודכן. ההחלטה תועבר לאפליקציה בסבב הסנכרון הבא.',
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
     * The booking is the user's to manage, the version they saw is the current one, it is visible at its stage, permission, requester-only, comment
     * and deadline - in that order: a booking that changed meanwhile is answered "changed" (409) - what the user needs to hear - and not "not
     * allowed" because of the stage it moved to. With $audit a refusal for lack of permission is written to the audit log.
     * @throws DomainError
     */
    private function assertMayChange(AccessScope $scope, ?Booking $booking, int $serial, StatusBookingEnum $target, string $comment, int $expectedVersion, bool $audit): void
    {
        $user = $scope->user;

        if ($booking === null || !$scope->managesBooking($booking->unit_id, (int) $booking->booking_type_id)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'not_visible', $user, ['booking_serial' => $serial]);

            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        if ((int) $booking->version !== $expectedVersion) {
            throw new DomainError('הקריאה עודכנה בינתיים (ייתכן שבאפליקציה). יש לרענן ולבדוק את המצב העדכני.', 409);
        }

        if (!$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, (int) $booking->status_id)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'not_visible', $user, ['booking_serial' => $serial]);

            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        $context = ['booking_serial' => $serial, 'unit_id' => $booking->unit_id, 'booking_type_id' => $booking->booking_type_id];
        $current = $booking->statusEnum();

        if (Workflow::isRequesterOnly($target)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'requester_only', $user, $context);

            throw new DomainError('ביטול על ידי המזמין מתבצע באפליקציה בלבד', 403);
        }

        if (!$scope->workflow()->canChangeTo($current, $target, $scope->permissions, (int) $booking->booking_type_id)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, "transition {$current->value}->{$target->value} not allowed", $user, $context);

            throw new DomainError('הפעולה אינה זמינה עבורך בשלב הנוכחי של הקריאה', 403);
        }

        if ($error = Workflow::validateComment($target, $comment)) {
            throw new DomainError($error, 422);
        }

        $this->assertCancellationDeadline($booking, $target);
    }

    /** The answer given to the same request before, or null when the key is new. @throws DomainError when the key was used for another request */
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

    /** A food booking can be canceled only so many business days before its supply date (the setting of the unit). @throws DomainError */
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

    /** The vice approver who passed the booking on is tied to it, so the dashboards can filter by who handled it. */
    private function linkViceApprover(Booking $booking, StatusBookingEnum $target, User $user): void
    {
        if (!in_array($target, [StatusBookingEnum::VICE_APPROVER_APPROVED, StatusBookingEnum::VICE_APPROVER_REJECTED], true)) {
            return;
        }

        ViceApproverHasBooking::query()->updateOrCreate(
            ['booking_id' => $booking->id, 'user_id' => $user->id],
            ['approved_by_vice_approver' => $target === StatusBookingEnum::VICE_APPROVER_APPROVED, 'is_deleted' => false],
        );
    }
}

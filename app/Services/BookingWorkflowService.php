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

/** Validates and applies a status change by a person: checks visibility, Workflow permission, comment rules, deadline, no open conflict and version. BookingStatusExportService sends the decision to the mobile app. */
class BookingWorkflowService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BookingStatusRecorder $recorder,
    ) {
    }

    /**
     * @param ?string $idempotencyKey the client's Idempotency-Key: a repeated request with the same key gets the first answer
     * @return array{serial_number: int, status_id: int, status_label: ?string, version: int, correlation_id: string, sync_state: string, message: string}
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

        // Unlocked checks run before the transaction so a refusal's audit row is not rolled back.
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
                // A hold does not move the booking: the row says where it stays.
                'after' => ['status_id' => $target->value, 'version' => (int) $booking->version]
                    + ($booking->parked_status_id !== null ? ['parked_status_id' => (int) $booking->parked_status_id] : []),
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

    /** Checks ownership, version, stage visibility, permission, requester-only, comment and deadline; a stale version answers 409. @param bool $audit log a permission refusal */
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

        // Whose turn it is follows the position: a booking on hold stays with the stage it was put on hold from.
        if (!$scope->canSeeBooking($booking->unit_id, (int) $booking->booking_type_id, $booking->positionStatus()->value)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'not_visible', $user, ['booking_serial' => $serial]);

            throw new DomainError('הקריאה לא ניתנת לביצוע.', 404);
        }

        $context = ['booking_serial' => $serial, 'unit_id' => $booking->unit_id, 'booking_type_id' => $booking->booking_type_id];
        $current = $booking->statusEnum();

        if (Workflow::isRequesterOnly($target)) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, 'requester_only', $user, $context);

            throw new DomainError('ביטול על ידי המזמין מתבצע באפליקציה בלבד', 403);
        }

        if (!$scope->workflow()->canChangeTo($booking->positionStatus(), $target, $scope->actingPermissions, (int) $booking->booking_type_id, $booking->holdStatus())) {
            $audit && $this->audit->denied('booking.status_change', 'booking', $serial, "transition {$current->value}->{$target->value} not allowed", $user, $context);

            throw new DomainError('הפעולה אינה זמינה עבורך בשלב הנוכחי של הקריאה', 403);
        }

        if ($error = Workflow::validateComment($target, $comment)) {
            throw new DomainError($error, 422);
        }

        $this->assertCancellationDeadline($booking, $target);
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

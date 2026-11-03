<?php

namespace App\Domain\Workflow;

use App\Enums\BookingStageEnum;
use App\Enums\PermissionEnum;
use App\Enums\StatusBookingEnum as Status;

/**
 * The booking route rules, shared by the UI buttons (BookingResource::next_actions) and the server checks (BookingWorkflowService).
 * The route runs from the bottom up: control officer (only on the types that have the role), vice approver, approver.
 * Turn-based: only the role whose turn it is may act, except the admin of the booking type, who holds every turn (control officer, vice approver
 * and approver). A role may also act at a stage below its own, without waiting for the role that owns it (skipTransitions): the approver and the
 * admin decide finally - approve or reject - from the control officer's and the vice approver's stage, and the vice approver takes a booking over
 * from the control officer.
 * A hold (TRANSPORTATION: 8, 9, 10) never moves a booking: the stage, the buttons and the visibility stay those of the status it was put on hold
 * from. So every method here takes that status as $current - the status that decides whose turn it is (Booking::positionStatus()) - and, where
 * buttons are built, the hold itself as $hold, which is not offered again. A hold with nowhere recorded is its own $current: the supplier stage.
 * Status ids are the mobile app's - never renumber.
 */
final class Workflow
{
    public const COMMENT_MAX_LENGTH = 100;
    public const COMMENT_REGEX = '~^[א-ת ]+$~u';

    /** @param array<int, int> $controlOfficerBookingTypeIds types whose route starts at the control officer (none by default, or nobody could act) */
    public function __construct(
        private readonly array $controlOfficerBookingTypeIds = [],
        private readonly ?int $transportationBookingTypeId = null,
        private readonly ?int $barberBookingTypeId = null,
    ) {
    }

    /** @param iterable<string> $permissionNames the user's permission names @return array{isControlOfficer: bool, isViceApprover: bool, isApprover: bool, isAdmin: bool} */
    public static function roleFlags(iterable $permissionNames, int $bookingTypeId): array
    {
        $names = is_array($permissionNames) ? $permissionNames : iterator_to_array($permissionNames, false);
        $has = fn (PermissionEnum $permission): bool => in_array($permission->value . $bookingTypeId, $names, true);

        return [
            'isControlOfficer' => $has(PermissionEnum::TRANSPORTATION_CONTROL_OFFICER),
            'isViceApprover' => $has(PermissionEnum::VICE_APPROVER),
            'isApprover' => $has(PermissionEnum::APPROVER),
            // The strongest role of the type: acts at every stage, as whoever's turn it is.
            'isAdmin' => $has(PermissionEnum::ADMIN),
        ];
    }

    public function hasControlOfficerStage(int $bookingTypeId): bool
    {
        return in_array($bookingTypeId, $this->controlOfficerBookingTypeIds, true);
    }

    /** Who or what a booking in this status is waiting for. */
    public function stageOf(Status $status, int $bookingTypeId): BookingStageEnum
    {
        return match (true) {
            $status->isTerminal() => BookingStageEnum::CLOSED,
            $status === Status::RECEIVED => $this->hasControlOfficerStage($bookingTypeId) ? BookingStageEnum::CONTROL_OFFICER : BookingStageEnum::VICE_APPROVER,
            $status->isWaitingForViceApprover() => BookingStageEnum::VICE_APPROVER,
            $status->isWaitingForApprover() => BookingStageEnum::APPROVER,
            $status === Status::PENDING_FILE => BookingStageEnum::SUPPLIER_FILES,
            $status === Status::PENDING_PROCESSING => BookingStageEnum::SUPPLIER_APPROVAL,
            $status === Status::PENDING_APPROVED => BookingStageEnum::SUPPLIER_NUMBER,
            $status === Status::APPROVED => BookingStageEnum::REALIZATION,
            $status === Status::APPROVED_BY_BARBER => BookingStageEnum::BARBER_DONE,
            default => BookingStageEnum::CLOSED,
        };
    }

    /**
     * The moves of the role whose turn it is (the admin holds every turn), then the moves of the roles above that stage that skip it.
     * Each status is listed once, with the label of the first role that offers it.
     * @param Status $current the status that decides whose turn it is
     * @param ?Status $hold the hold the booking is on, when $current is the status it was put on hold from: not offered again
     * @param array{isControlOfficer?: bool, isViceApprover?: bool, isApprover?: bool, isAdmin?: bool} $flags
     * @return array<int, array{status: Status, label: string}>
     */
    public function allowedTransitions(Status $current, array $flags, int $bookingTypeId, ?Status $hold = null): array
    {
        $transitions = [];
        $moves = [...$this->turnTransitions($current, $flags, $bookingTypeId, $hold), ...$this->skipTransitions($current, $flags, $bookingTypeId, $hold)];

        foreach ($moves as $transition) {
            $transitions[$transition['status']->value] ??= $transition;
        }

        return array_values($transitions);
    }

    /**
     * The moves of the role whose turn it is (the admin holds every turn).
     * @param array{isControlOfficer?: bool, isViceApprover?: bool, isApprover?: bool, isAdmin?: bool} $flags
     * @return array<int, array{status: Status, label: string}>
     */
    private function turnTransitions(Status $current, array $flags, int $bookingTypeId, ?Status $hold = null): array
    {
        $isAdmin = !empty($flags['isAdmin']);
        $isControlOfficer = $isAdmin || !empty($flags['isControlOfficer']);
        $isViceApprover = $isAdmin || !empty($flags['isViceApprover']);
        $isApprover = $isAdmin || !empty($flags['isApprover']);
        // The hold the booking is on is not offered again: the one it was put on, or its own status when it is parked nowhere (an old hold).
        $except = $hold ?? $current;

        return match ($this->stageOf($current, $bookingTypeId)) {
            BookingStageEnum::CONTROL_OFFICER => $isControlOfficer ? $this->controlOfficerMoves($except, $bookingTypeId) : [],
            BookingStageEnum::VICE_APPROVER => $isViceApprover ? $this->viceApproverMoves($except, $bookingTypeId) : [],
            BookingStageEnum::APPROVER,
            BookingStageEnum::SUPPLIER_FILES,
            BookingStageEnum::SUPPLIER_APPROVAL,
            BookingStageEnum::SUPPLIER_NUMBER => $isApprover ? $this->approverMoves($except, $bookingTypeId) : [],
            BookingStageEnum::REALIZATION => $this->realizationMoves($bookingTypeId, $isViceApprover, $isApprover),
            BookingStageEnum::BARBER_DONE => $isApprover ? $this->closingMoves() : [],
            BookingStageEnum::CLOSED => [],
        };
    }

    /**
     * What a role above the stage a booking waits at may do without waiting for the role that owns the stage:
     *  - the approver and the admin of the type decide finally - approve or reject - from the control officer's and the vice approver's stage,
     *    straight to the realization stage or to the rejection, with no status of the skipped stages in between;
     *  - the vice approver takes a booking over from the control officer (transportation), with the moves of their own stage.
     * Only the decision is skipped: correcting values stays with the role whose turn it is (canEditValues reads turnTransitions only), and a role
     * never reaches above its own stage.
     * @param array{isControlOfficer?: bool, isViceApprover?: bool, isApprover?: bool, isAdmin?: bool} $flags
     * @return array<int, array{status: Status, label: string}>
     */
    private function skipTransitions(Status $current, array $flags, int $bookingTypeId, ?Status $hold = null): array
    {
        $stage = $this->stageOf($current, $bookingTypeId);
        $moves = [];

        if (!empty($flags['isViceApprover']) && $stage === BookingStageEnum::CONTROL_OFFICER) {
            $moves = $this->viceApproverMoves($hold ?? $current, $bookingTypeId);
        }

        $belowTheApprover = in_array($stage, [BookingStageEnum::CONTROL_OFFICER, BookingStageEnum::VICE_APPROVER], true);

        if ((!empty($flags['isApprover']) || !empty($flags['isAdmin'])) && $belowTheApprover) {
            $moves = [...$moves, ...$this->finalDecisionMoves()];
        }

        return $moves;
    }

    /** @param Status $except the hold the booking is on, which is not offered again (any other status leaves the holds as they are) @return array<int, array{status: Status, label: string}> */
    private function controlOfficerMoves(Status $except, int $bookingTypeId): array
    {
        return [
            ['status' => Status::CONTROL_OFFICER_APPROVED, 'label' => 'אישור והעברה לגורם המטפל'],
            ['status' => Status::CONTROL_OFFICER_REJECTED, 'label' => 'דחייה'],
            ...$this->holdMoves($except, $bookingTypeId),
        ];
    }

    /** @param Status $except see controlOfficerMoves @return array<int, array{status: Status, label: string}> */
    private function viceApproverMoves(Status $except, int $bookingTypeId): array
    {
        return [
            ['status' => Status::VICE_APPROVER_APPROVED, 'label' => 'אישור והעברה למפקד התחום'],
            ['status' => Status::VICE_APPROVER_REJECTED, 'label' => 'דחייה'],
            ...$this->holdMoves($except, $bookingTypeId),
        ];
    }

    /** @param Status $except see controlOfficerMoves @return array<int, array{status: Status, label: string}> */
    private function approverMoves(Status $except, int $bookingTypeId): array
    {
        return [...$this->finalDecisionMoves(), ...$this->holdMoves($except, $bookingTypeId)];
    }

    /** The approver's decision on a request: the final approval or the final rejection (which needs a comment). @return array<int, array{status: Status, label: string}> */
    private function finalDecisionMoves(): array
    {
        return [
            ['status' => Status::APPROVED, 'label' => 'אישור סופי'],
            ['status' => Status::REJECTED, 'label' => 'דחייה סופית'],
        ];
    }

    /**
     * Transportation only: put the booking on hold on the supplier side (files, approval, number), except on the hold it is on. A hold does not
     * move the booking - it stays with the role that holds the stage - and its status is what the mobile app shows the requester.
     * @return array<int, array{status: Status, label: string}>
     */
    private function holdMoves(Status $except, int $bookingTypeId): array
    {
        if ($this->transportationBookingTypeId === null || $bookingTypeId !== $this->transportationBookingTypeId) {
            return [];
        }

        $labels = [
            Status::PENDING_FILE->value => 'העבר לממתין לקבצים מהלקוח',
            Status::PENDING_PROCESSING->value => 'העבר לממתין לאישור ספק',
            Status::PENDING_APPROVED->value => 'העבר לממתין למספר ספק',
        ];
        $moves = [];

        foreach ([Status::PENDING_FILE, Status::PENDING_PROCESSING, Status::PENDING_APPROVED] as $hold) {
            if ($hold !== $except) {
                $moves[] = ['status' => $hold, 'label' => $labels[$hold->value]];
            }
        }

        return $moves;
    }

    /** After the final approval: the barber marks a haircut done (vice approver's move), the approver completes or cancels. @return array<int, array{status: Status, label: string}> */
    private function realizationMoves(int $bookingTypeId, bool $isViceApprover, bool $isApprover): array
    {
        $isBarber = $this->barberBookingTypeId !== null && $bookingTypeId === $this->barberBookingTypeId;
        $moves = [];

        if ($isBarber && $isViceApprover) {
            $moves[] = ['status' => Status::APPROVED_BY_BARBER, 'label' => 'אישור על ידי הספר'];
        }

        if ($isApprover) {
            // A haircut is completed after the barber's mark (BARBER_DONE), not straight from the approval.
            if (!$isBarber) {
                $moves[] = ['status' => Status::COMPLETED, 'label' => 'סיום קריאה'];
            }

            $moves[] = ['status' => Status::CANCELED_BY_APPROVER, 'label' => 'ביטול קריאה'];
        }

        return $moves;
    }

    /** @return array<int, array{status: Status, label: string}> */
    private function closingMoves(): array
    {
        return [
            ['status' => Status::COMPLETED, 'label' => 'סיום קריאה'],
            ['status' => Status::CANCELED_BY_APPROVER, 'label' => 'ביטול קריאה'],
        ];
    }

    /**
     * Single source for the UI buttons and the server check.
     * @param Status $current the status that decides whose turn it is (Booking::positionStatus())
     * @param ?Status $hold the hold the booking is on, if any
     * @param iterable<string> $permissionNames
     * @return array<int, array{status: Status, label: string}>
     */
    public function availableActions(Status $current, iterable $permissionNames, int $bookingTypeId, ?Status $hold = null): array
    {
        return $this->allowedTransitions($current, self::roleFlags($permissionNames, $bookingTypeId), $bookingTypeId, $hold);
    }

    /** @param iterable<string> $permissionNames */
    public function canChangeTo(Status $current, Status $target, iterable $permissionNames, int $bookingTypeId, ?Status $hold = null): bool
    {
        foreach ($this->availableActions($current, $permissionNames, $bookingTypeId, $hold) as $action) {
            if ($action['status'] === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Date/time value correction: whoever's turn it is, until the final approval. Skipping a stage (skipTransitions) does not make it the skipper's
     * turn.
     * @param iterable<string> $permissionNames
     */
    public function canEditValues(Status $current, iterable $permissionNames, int $bookingTypeId): bool
    {
        if ($current->isTerminal() || $current === Status::APPROVED || $current === Status::APPROVED_BY_BARBER) {
            return false;
        }

        return $this->turnTransitions($current, self::roleFlags($permissionNames, $bookingTypeId), $bookingTypeId) !== [];
    }

    /** Statuses only the requester may set (mobile app only). */
    public static function isRequesterOnly(Status $target): bool
    {
        return $target === Status::CANCELED_BY_USER;
    }

    /** @return ?string Hebrew error, or null when the comment is fine */
    public static function validateComment(Status $target, ?string $comment): ?string
    {
        $comment = trim((string) $comment);
        $required = in_array($target, [Status::CONTROL_OFFICER_REJECTED, Status::VICE_APPROVER_REJECTED, Status::REJECTED, Status::CANCELED_BY_APPROVER], true);

        if ($required && $comment === '') {
            return 'יש לצרף הערה לביצוע פעולה זו';
        }

        if ($comment === '') {
            return null;
        }

        if (mb_strlen($comment) > self::COMMENT_MAX_LENGTH) {
            return 'ההערה ארוכה מ-' . self::COMMENT_MAX_LENGTH . ' תווים';
        }

        if (!preg_match(self::COMMENT_REGEX, $comment)) {
            return 'ההערה יכולה להכיל אותיות בעברית ורווחים בלבד';
        }

        return null;
    }

    /** Supply date minus N business days (Sunday to Thursday), end of that day. */
    public static function lastAllowedCancellation(\DateTimeImmutable $supplyDate, int $businessDays): \DateTimeImmutable
    {
        $day = $supplyDate->setTime(0, 0);
        $left = $businessDays;

        while ($left > 0) {
            $day = $day->modify('-1 day');
            $weekday = (int) $day->format('w');

            if ($weekday !== 5 && $weekday !== 6) {
                $left--;
            }
        }

        return $day->setTime(23, 59, 59);
    }
}

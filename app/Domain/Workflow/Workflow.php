<?php

namespace App\Domain\Workflow;

use App\Enums\BookingStageEnum;
use App\Enums\PermissionEnum;
use App\Enums\StatusBookingEnum as Status;

/**
 * The rules of the booking route, in one place - the buttons the UI shows (BookingResource::next_actions) and the checks the server makes
 * (BookingWorkflowService) both come from here. No database access: the booking type ids it needs are given to the constructor
 * (the container builds it in AppServiceProvider), so the rules are unit-tested standalone.
 *
 * Turn-based: only the role whose turn it is on the booking's route may act.
 *   control officer stage  -> control officer: 17 / 18 (+ supplier holds in transportation)
 *   vice approver stage    -> vice approver:   15 / 16 (+ supplier holds in transportation)
 *   approver stage / holds -> approver:         3 / 4  (+ other supplier holds in transportation)
 *   approved               -> approver: 5 / 7; in barber the barber (vice approver) marks 11 and the approver may cancel (7)
 *   barber done (11)       -> approver: 5 / 7
 * The admin (camp commander) sees and manages but never acts on a booking. Status ids and their meaning are the mobile app's - never renumber.
 */
final class Workflow
{
    public const COMMENT_MAX_LENGTH = 100;
    public const COMMENT_REGEX = '~^[א-ת ]+$~u';

    /**
     * @param array<int, int> $controlOfficerBookingTypeIds booking types whose route starts at the control officer: the types that have the
     *        control officer role in booking_type_has_roles. None by default - a stage nobody can act in would leave the bookings stuck.
     */
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
            // View and manage only - an admin never acts on a booking.
            'isAdmin' => $has(PermissionEnum::ADMIN),
        ];
    }

    /** Does the route of this booking type start at the control officer? */
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
     * What a user with these role flags may do with a booking in the current status.
     * @param array{isControlOfficer?: bool, isViceApprover?: bool, isApprover?: bool, isAdmin?: bool} $flags
     * @return array<int, array{status: Status, label: string}>
     */
    public function allowedTransitions(Status $current, array $flags, int $bookingTypeId): array
    {
        $transitions = [];
        $isTransportation = $this->transportationBookingTypeId !== null && $bookingTypeId === $this->transportationBookingTypeId;

        $holds = function (array &$transitions) use ($current, $isTransportation): void {
            if (!$isTransportation) {
                return;
            }

            $labels = [
                Status::PENDING_FILE->value => 'העבר לממתין לקבצים מהלקוח',
                Status::PENDING_PROCESSING->value => 'העבר לממתין לאישור ספק',
                Status::PENDING_APPROVED->value => 'העבר לממתין למספר ספק',
            ];

            foreach ([Status::PENDING_FILE, Status::PENDING_PROCESSING, Status::PENDING_APPROVED] as $hold) {
                if ($hold !== $current) {
                    $transitions[] = ['status' => $hold, 'label' => $labels[$hold->value]];
                }
            }
        };

        switch ($this->stageOf($current, $bookingTypeId)) {
            case BookingStageEnum::CONTROL_OFFICER:
                if (!empty($flags['isControlOfficer'])) {
                    $transitions[] = ['status' => Status::CONTROL_OFFICER_APPROVED, 'label' => 'אישור והעברה לגורם המטפל'];
                    $transitions[] = ['status' => Status::CONTROL_OFFICER_REJECTED, 'label' => 'דחייה'];
                    $holds($transitions);
                }
                break;

            case BookingStageEnum::VICE_APPROVER:
                if (!empty($flags['isViceApprover'])) {
                    $transitions[] = ['status' => Status::VICE_APPROVER_APPROVED, 'label' => 'אישור והעברה למפקד התחום'];
                    $transitions[] = ['status' => Status::VICE_APPROVER_REJECTED, 'label' => 'דחייה'];
                    $holds($transitions);
                }
                break;

            case BookingStageEnum::APPROVER:
            case BookingStageEnum::SUPPLIER_FILES:
            case BookingStageEnum::SUPPLIER_APPROVAL:
            case BookingStageEnum::SUPPLIER_NUMBER:
                if (!empty($flags['isApprover'])) {
                    $transitions[] = ['status' => Status::APPROVED, 'label' => 'אישור סופי'];
                    $transitions[] = ['status' => Status::REJECTED, 'label' => 'דחייה סופית'];
                    $holds($transitions);
                }
                break;

            case BookingStageEnum::REALIZATION:
                if ($this->barberBookingTypeId !== null && $bookingTypeId === $this->barberBookingTypeId && !empty($flags['isViceApprover'])) {
                    $transitions[] = ['status' => Status::APPROVED_BY_BARBER, 'label' => 'אישור על ידי הספר'];
                }

                if (!empty($flags['isApprover'])) {
                    if ($bookingTypeId !== $this->barberBookingTypeId) {
                        $transitions[] = ['status' => Status::COMPLETED, 'label' => 'סיום קריאה'];
                    }

                    $transitions[] = ['status' => Status::CANCELED_BY_APPROVER, 'label' => 'ביטול קריאה'];
                }
                break;

            case BookingStageEnum::BARBER_DONE:
                if (!empty($flags['isApprover'])) {
                    $transitions[] = ['status' => Status::COMPLETED, 'label' => 'סיום קריאה'];
                    $transitions[] = ['status' => Status::CANCELED_BY_APPROVER, 'label' => 'ביטול קריאה'];
                }
                break;

            case BookingStageEnum::CLOSED:
                break;
        }

        return $transitions;
    }

    /** Everything the user may do now - the single source for the UI buttons and for the server's check. @param iterable<string> $permissionNames @return array<int, array{status: Status, label: string}> */
    public function availableActions(Status $current, iterable $permissionNames, int $bookingTypeId): array
    {
        return $this->allowedTransitions($current, self::roleFlags($permissionNames, $bookingTypeId), $bookingTypeId);
    }

    /** May the user move the booking from $current to $target? @param iterable<string> $permissionNames */
    public function canChangeTo(Status $current, Status $target, iterable $permissionNames, int $bookingTypeId): bool
    {
        foreach ($this->availableActions($current, $permissionNames, $bookingTypeId) as $action) {
            if ($action['status'] === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Correction of the editable date/time values: whoever's turn it is, until the final approval.
     * (The mobile app's rule - "status_id below 3" - let an approver edit only a booking that nobody had passed on yet.)
     * @param iterable<string> $permissionNames
     */
    public function canEditValues(Status $current, iterable $permissionNames, int $bookingTypeId): bool
    {
        if ($current->isTerminal() || $current === Status::APPROVED || $current === Status::APPROVED_BY_BARBER) {
            return false;
        }

        return $this->availableActions($current, $permissionNames, $bookingTypeId) !== [];
    }

    /** Statuses only the requester may set - done in the mobile app only. */
    public static function isRequesterOnly(Status $target): bool
    {
        return $target === Status::CANCELED_BY_USER;
    }

    /** The comment rules of a status change. @return ?string the Hebrew error, or null when the comment is fine */
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

    /** The last moment a food booking can still be canceled: the supply date minus N business days (Sunday to Thursday), end of that day. */
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

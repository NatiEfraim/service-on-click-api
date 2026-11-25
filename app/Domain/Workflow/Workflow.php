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
 * The barber booking is the exception: its route has one step, the barber's answer - approve (11) or reject (12) - and none of the vice approver's
 * or the approver's statuses (barberMoves). The barber is the APPROVER of the type (approver_5); the admin holds that turn too, as on every type.
 * The type has no vice approver (BookingTypeHasRoleSeeder), so nobody skips or waits for one.
 * Status 19 (ממתין לגורם חיצוני, the types of $waitingForExternalPartyBookingTypeIds only): every managing role may park a booking there from its
 * own turn or skip (externalPartyMoves), and only the approver or the admin closes it - the final approval or rejection. No way back to an earlier
 * stage, no hold from it, and the requester cannot cancel while it waits there (StatusBookingEnum::canBeCanceledByUser).
 * The infosec types (15, 16) have no vice approver or approver either: their handler (infosec_officer_N) is the whole route,
 * like the barber - one answer, approve (3) or reject (4), then the closing (5 / 7) - see officerMoves(). The stages stay the generic ones
 * (vice_approver = "waiting for the handling party", realization), so the screens need no new stage.
 * A hold (TRANSPORTATION: 8, 9, 10) never moves a booking: the stage, the buttons and the visibility stay those of the status it was put on hold
 * from. So every method here takes that status as $current - the status that decides whose turn it is (Booking::positionStatus()) - and, where
 * buttons are built, the hold itself as $hold, which is not offered again. A hold with nowhere recorded is its own $current: the supplier stage.
 * Status ids are the mobile app's - never renumber.
 *
 * A booking type may have a ROUTE OF ITS OWN (TypeRoute, e.g. the clearance grant): then every public method here asks the route first and the
 * generic rules above are not consulted for it. A route's manual transition is open to whoever holds "{role}_{type}" for one of its roles, or
 * "admin_{type}" (the super admin's synthetic permission), so the super admin keeps every manual move.
 */
final class Workflow
{
    public const COMMENT_MAX_LENGTH = 100;
    public const COMMENT_REGEX = '~^[א-ת ]+$~u';

    /**
     * @param array<int, int> $controlOfficerBookingTypeIds types whose route starts at the control officer (none by default, or nobody could act)
     * @param array<int, int> $waitingForExternalPartyBookingTypeIds types that may be parked at status 19 (none by default)
     * @param array<int, TypeRoute> $routes the types with a route of their own, by booking type id
     */
    public function __construct(
        private readonly array $controlOfficerBookingTypeIds = [],
        private readonly ?int $transportationBookingTypeId = null,
        private readonly ?int $barberBookingTypeId = null,
        private readonly array $waitingForExternalPartyBookingTypeIds = [],
        private readonly array $routes = [],
    ) {
    }

    //----------------------------- Routes of their own ------------------------//

    public function routeFor(int $bookingTypeId): ?TypeRoute
    {
        return $this->routes[$bookingTypeId] ?? null;
    }

    public function hasRoute(int $bookingTypeId): bool
    {
        return isset($this->routes[$bookingTypeId]);
    }

    /** @return array<int, int> */
    public function routeTypeIds(): array
    {
        return array_keys($this->routes);
    }

    /** @return array<int, int> The statuses that mean "done": completed (5), and the successful end of every route of its own. */
    public function completedStatusIds(): array
    {
        $ids = [Status::COMPLETED->value];

        foreach ($this->routes as $route) {
            $ids[] = $route->successStatus();
        }

        return array_values(array_unique($ids));
    }

    /** The status a new booking of the type is created in. */
    public function initialStatus(int $bookingTypeId): Status
    {
        $route = $this->routeFor($bookingTypeId);

        return $route === null ? Status::RECEIVED : Status::from($route->initialStatus());
    }

    /** The manual move from one status to another on a route type, or null (no route, or no such move). */
    public function transitionFor(int $bookingTypeId, Status $from, Status $to): ?Transition
    {
        $route = $this->routeFor($bookingTypeId);

        if ($route === null) {
            return null;
        }

        foreach ($route->transitionsFrom($from->value) as $transition) {
            if ($transition->isManual() && $transition->to === $to->value) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * Whether the permission names hold one of the roles on the type: "{role}_{type}" for a role in $roles, or "admin_{type}".
     * @param iterable<string> $permissionNames @param array<int, \App\Enums\RoleEnum> $roles
     */
    public function holdsRouteRole(iterable $permissionNames, array $roles, int $bookingTypeId): bool
    {
        $names = is_array($permissionNames) ? $permissionNames : iterator_to_array($permissionNames, false);

        if (in_array(PermissionEnum::ADMIN->value . $bookingTypeId, $names, true)) {
            return true;
        }

        foreach ($roles as $role) {
            if (in_array($role->toPermissionEnum()->value . $bookingTypeId, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /** The manual moves of a route type the permissions allow from this status. @param iterable<string> $permissionNames @return array<int, array{status: Status, label: string, transition: Transition}> */
    private function routeActions(TypeRoute $route, Status $current, iterable $permissionNames, int $bookingTypeId): array
    {
        $actions = [];

        foreach ($route->transitionsFrom($current->value) as $transition) {
            if ($transition->isManual() && $this->holdsRouteRole($permissionNames, $transition->roles, $bookingTypeId)) {
                $actions[] = ['status' => Status::from($transition->to), 'label' => $transition->label, 'transition' => $transition];
            }
        }

        return $actions;
    }

    /** @param iterable<string> $permissionNames the user's permission names @return array{isControlOfficer: bool, isViceApprover: bool, isApprover: bool, isAdmin: bool} */
    public static function roleFlags(iterable $permissionNames, int $bookingTypeId): array
    {
        $names = is_array($permissionNames) ? $permissionNames : iterator_to_array($permissionNames, false);
        $has = fn (PermissionEnum $permission): bool => in_array($permission->value . $bookingTypeId, $names, true);

        return [
            // Each control officer holds "{their prefix}_{their own type}" only, so at most one of these can match a type.
            'isControlOfficer' => $has(PermissionEnum::TRANSPORTATION_CONTROL_OFFICER) || $has(PermissionEnum::OUT_UNIT_FOOD_CONTROL_OFFICER),
            'isViceApprover' => $has(PermissionEnum::VICE_APPROVER),
            'isApprover' => $has(PermissionEnum::APPROVER),
            // The domain handler of an infosec type (15, 16). ICT (20-25) runs on IctServiceDeskRoute and never reaches here.
            'isOfficer' => $has(PermissionEnum::INFOSEC_OFFICER),
            // The strongest role of the type: acts at every stage, as whoever's turn it is.
            'isAdmin' => $has(PermissionEnum::ADMIN),
        ];
    }

    public function hasControlOfficerStage(int $bookingTypeId): bool
    {
        return in_array($bookingTypeId, $this->controlOfficerBookingTypeIds, true);
    }

    /** Whether a booking of the type may be parked at status 19 (ממתין לגורם חיצוני). */
    public function hasWaitingForExternalParty(int $bookingTypeId): bool
    {
        return in_array($bookingTypeId, $this->waitingForExternalPartyBookingTypeIds, true);
    }

    private function isBarberBooking(int $bookingTypeId): bool
    {
        return $this->barberBookingTypeId !== null && $bookingTypeId === $this->barberBookingTypeId;
    }

    /**
     * The barber route has one step: a booking waits for the barber until the barber answers. 11 (approved) is the end of the route here - the
     * requester may still cancel it in the app and a blocked date may still cancel it, but nobody has anything left to do. A booking left in a status
     * of the old route (2, 3, 15, 16...) waits for the barber too, so none is stranded.
     */
    private function barberStageOf(Status $status): BookingStageEnum
    {
        return $status->isTerminal() || $status === Status::APPROVED_BY_BARBER ? BookingStageEnum::CLOSED : BookingStageEnum::BARBER;
    }

    /** Who or what a booking in this status is waiting for. */
    public function stageOf(Status $status, int $bookingTypeId): BookingStageEnum
    {
        if (($route = $this->routeFor($bookingTypeId)) !== null) {
            return $route->stageOf($status->value);
        }

        if ($this->isBarberBooking($bookingTypeId)) {
            return $this->barberStageOf($status);
        }

        return match (true) {
            $status->isTerminal() => BookingStageEnum::CLOSED,
            $status === Status::RECEIVED => $this->hasControlOfficerStage($bookingTypeId) ? BookingStageEnum::CONTROL_OFFICER : BookingStageEnum::VICE_APPROVER,
            $status->isWaitingForViceApprover() => BookingStageEnum::VICE_APPROVER,
            $status->isWaitingForApprover() => BookingStageEnum::APPROVER,
            $status === Status::PENDING_FILE => BookingStageEnum::SUPPLIER_FILES,
            $status === Status::PENDING_PROCESSING => BookingStageEnum::SUPPLIER_APPROVAL,
            $status === Status::PENDING_APPROVED => BookingStageEnum::SUPPLIER_NUMBER,
            $status === Status::WAITING_FOR_EXTERNAL_PARTY => BookingStageEnum::EXTERNAL_PARTY,
            $status === Status::APPROVED => BookingStageEnum::REALIZATION,
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
        // The officer of an infosec / ICT type holds the one step of its route: the answer, wherever an old booking waits for it, and the closing.
        $isOfficer = !empty($flags['isOfficer']);
        // The hold the booking is on is not offered again: the one it was put on, or its own status when it is parked nowhere (an old hold).
        $except = $hold ?? $current;

        return match ($this->stageOf($current, $bookingTypeId)) {
            BookingStageEnum::CONTROL_OFFICER => $isControlOfficer ? $this->controlOfficerMoves($except, $bookingTypeId) : [],
            BookingStageEnum::VICE_APPROVER => $isViceApprover ? $this->viceApproverMoves($except, $bookingTypeId) : ($isOfficer ? $this->officerMoves() : []),
            BookingStageEnum::APPROVER => $isApprover ? $this->approverMoves($except, $bookingTypeId) : ($isOfficer ? $this->officerMoves() : []),
            BookingStageEnum::SUPPLIER_FILES,
            BookingStageEnum::SUPPLIER_APPROVAL,
            BookingStageEnum::SUPPLIER_NUMBER => $isApprover ? $this->approverMoves($except, $bookingTypeId) : [],
            // Only the approver (or the admin) answers the external party's wait: the final approval or rejection, nothing else.
            BookingStageEnum::EXTERNAL_PARTY => $isApprover ? $this->finalDecisionMoves() : [],
            // The barber is the approver of the type (approver_5); the admin holds the turn too. The type has no vice approver.
            BookingStageEnum::BARBER => $isApprover ? $this->barberMoves() : [],
            BookingStageEnum::REALIZATION => $isApprover || $isOfficer ? $this->closingMoves() : [],
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
            $moves = [...$moves, ...$this->finalDecisionMoves(), ...$this->externalPartyMoves($bookingTypeId)];
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
            ...$this->externalPartyMoves($bookingTypeId),
        ];
    }

    /** @param Status $except see controlOfficerMoves @return array<int, array{status: Status, label: string}> */
    private function viceApproverMoves(Status $except, int $bookingTypeId): array
    {
        return [
            ['status' => Status::VICE_APPROVER_APPROVED, 'label' => 'אישור והעברה למפקד התחום'],
            ['status' => Status::VICE_APPROVER_REJECTED, 'label' => 'דחייה'],
            ...$this->holdMoves($except, $bookingTypeId),
            ...$this->externalPartyMoves($bookingTypeId),
        ];
    }

    /** @param Status $except see controlOfficerMoves @return array<int, array{status: Status, label: string}> */
    private function approverMoves(Status $except, int $bookingTypeId): array
    {
        return [...$this->finalDecisionMoves(), ...$this->holdMoves($except, $bookingTypeId), ...$this->externalPartyMoves($bookingTypeId)];
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

    /**
     * Parking a booking for an outside party's answer (status 19), on the types that know it. It never comes up as $except: at 19 itself the
     * stage is EXTERNAL_PARTY, where only the final decisions are offered - so there is no way from 19 to a hold or back to an earlier stage.
     * @return array<int, array{status: Status, label: string}>
     */
    private function externalPartyMoves(int $bookingTypeId): array
    {
        if (!$this->hasWaitingForExternalParty($bookingTypeId)) {
            return [];
        }

        return [['status' => Status::WAITING_FOR_EXTERNAL_PARTY, 'label' => 'העבר לממתין לגורם חיצוני']];
    }

    /**
     * The barber's answer, the one step of a barber booking: approve it (11) or reject it (12, which needs a comment). 13 is never a button: the
     * system sets it when the barber's schedule changes.
     * @return array<int, array{status: Status, label: string}>
     */
    private function barberMoves(): array
    {
        return [
            ['status' => Status::APPROVED_BY_BARBER, 'label' => 'אישור על ידי הספר'],
            ['status' => Status::CANCELED_BY_BARBER, 'label' => 'דחייה על ידי הספר'],
        ];
    }

    /**
     * The officer's answer on an infosec / ICT request, the one step of its route (like the barber's): approve it (3) or reject it (4, which
     * needs a comment). There is no vice approver or approver on these types, so nobody passes it on (15 / 16); the officer closes it after
     * the approval with the closing moves.
     * @return array<int, array{status: Status, label: string}>
     */
    private function officerMoves(): array
    {
        return [
            ['status' => Status::APPROVED, 'label' => 'אישור הבקשה'],
            ['status' => Status::REJECTED, 'label' => 'דחיית הבקשה'],
        ];
    }

    /** After the final approval: the approver completes or cancels the booking. @return array<int, array{status: Status, label: string}> */
    private function closingMoves(): array
    {
        return [
            ['status' => Status::COMPLETED, 'label' => 'סיום קריאה'],
            ['status' => Status::CANCELED_BY_APPROVER, 'label' => 'ביטול קריאה'],
        ];
    }

    /**
     * Single source for the UI buttons and the server check. On a route type the entries also carry the Transition.
     * @param Status $current the status that decides whose turn it is (Booking::positionStatus())
     * @param ?Status $hold the hold the booking is on, if any
     * @param iterable<string> $permissionNames
     * @return array<int, array{status: Status, label: string, transition?: Transition}>
     */
    public function availableActions(Status $current, iterable $permissionNames, int $bookingTypeId, ?Status $hold = null): array
    {
        if (($route = $this->routeFor($bookingTypeId)) !== null) {
            return $this->routeActions($route, $current, $permissionNames, $bookingTypeId);
        }

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
        if (($route = $this->routeFor($bookingTypeId)) !== null) {
            // The handler fields are written while the request sits in one of the route's writable statuses, by its roles.
            $roles = $route->handlerFieldStatuses()[$this->routePosition($route, $current)] ?? null;

            return $roles !== null && $this->holdsRouteRole($permissionNames, $roles, $bookingTypeId);
        }

        if ($current->isTerminal() || $current === Status::APPROVED || $current === Status::APPROVED_BY_BARBER) {
            return false;
        }

        return $this->turnTransitions($current, self::roleFlags($permissionNames, $bookingTypeId), $bookingTypeId) !== [];
    }

    /** The status of the route a booking stands in: a status the route knows, else (older data) its initial status. */
    public function routePosition(TypeRoute $route, Status $current): int
    {
        if (in_array($current->value, $route->statusIds(), true) || $current->isTerminal()) {
            return $current->value;
        }

        return $route->initialStatus();
    }

    /** Statuses only the requester may set (mobile app only). */
    public static function isRequesterOnly(Status $target): bool
    {
        return $target === Status::CANCELED_BY_USER;
    }

    /**
     * @param ?bool $required whether the move needs a comment; null = the generic rule (the rejections and the cancellation do), a route
     *                        transition passes its own flag
     * @return ?string Hebrew error, or null when the comment is fine
     */
    public static function validateComment(Status $target, ?string $comment, ?bool $required = null): ?string
    {
        $comment = trim((string) $comment);
        $required ??= in_array($target, [Status::CONTROL_OFFICER_REJECTED, Status::VICE_APPROVER_REJECTED, Status::REJECTED, Status::CANCELED_BY_APPROVER, Status::CANCELED_BY_BARBER], true);

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

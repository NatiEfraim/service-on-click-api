<?php

namespace App\Domain\Workflow\Routes;

use App\Domain\Workflow\Transition;
use App\Domain\Workflow\TypeRoute;
use App\Enums\BookingStageEnum;
use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum as Status;

/**
 * מוקד תקשוב - the route of the six ICT types (20-25), as the product's sheet (מחשוב.xlsx) describes it:
 *
 *   USER submits ──▶ 1 הקריאה נשלחה (the desk's queue)
 *       נציג מוקד (VICE_APPROVER) takes it ──▶ 111 בטיפול המוקד ──▶ 5 סיום טיפול / 7 נסגרה ללא ביצוע
 *       a short request may be closed straight from the queue (1 ──▶ 5 / 7)
 *   back:    111 ──▶ 1        the representative or the commander, comment optional, the handler is released
 *   reopen:  5 / 7 ──▶ 111    מפקד תקשוב (ADMIN_ICT) only, comment required
 *   future:  1 ──▶ 111        the system, once the type's automation succeeds (Transition::AUTO_AUTOMATION, off by default)
 *
 * The comment is optional everywhere but on a close without doing the work and on a reopen. The super admin holds every manual
 * move through the synthetic admin_{type} (Workflow::holdsRouteRole).
 */
final class IctServiceDeskRoute implements TypeRoute
{
    /** @return array<int, BookingTypeEnum> the six ICT types that run on this route */
    public static function bookingTypes(): array
    {
        return BookingTypeEnum::ictCases();
    }

    /** @return array<int, int> their DB ids (a type missing from the DB is skipped) */
    public static function bookingTypeIds(): array
    {
        return array_values(array_filter(array_map(
            fn (BookingTypeEnum $type): ?int => $type->getBookingTypeId(),
            self::bookingTypes(),
        )));
    }

    public function key(): string
    {
        return 'ict_service_desk';
    }

    public function initialStatus(): int
    {
        return Status::RECEIVED->value;
    }

    public function statusIds(): array
    {
        return [...$this->steps(), Status::CANCELED_BY_APPROVER->value, Status::CANCELED_BY_USER->value];
    }

    public function steps(): array
    {
        return [Status::RECEIVED->value, Status::IN_SERVICE_DESK->value, Status::COMPLETED->value];
    }

    public function terminalStatuses(): array
    {
        return [Status::COMPLETED->value, Status::CANCELED_BY_APPROVER->value, Status::CANCELED_BY_USER->value];
    }

    public function successStatus(): int
    {
        return Status::COMPLETED->value;
    }

    public function stageOf(int $statusId): BookingStageEnum
    {
        $status = Status::tryFrom($statusId);

        if ($status === null || $status->isTerminal()) {
            return BookingStageEnum::CLOSED;
        }

        return match ($status) {
            Status::IN_SERVICE_DESK => BookingStageEnum::SERVICE_DESK_HANDLING,
            // 1, and any generic status older data left a request in (2, 3, 15, 16): waiting in the desk's queue.
            default => BookingStageEnum::SERVICE_DESK_QUEUE,
        };
    }

    public function roles(): array
    {
        return [RoleEnum::VICE_APPROVER, RoleEnum::ADMIN_ICT];
    }

    public function ownerRole(): RoleEnum
    {
        return RoleEnum::ADMIN_ICT;
    }

    public function requesterRole(): RoleEnum
    {
        return RoleEnum::USER;
    }

    public function transitions(): array
    {
        $desk = [RoleEnum::VICE_APPROVER, RoleEnum::ADMIN_ICT];
        $commander = [RoleEnum::ADMIN_ICT];
        $received = Status::RECEIVED->value;
        $handling = Status::IN_SERVICE_DESK->value;
        $completed = Status::COMPLETED->value;
        $notDone = Status::CANCELED_BY_APPROVER->value;

        return [
            // T1: a representative takes the request - becomes its handler when nobody is assigned.
            new Transition(from: $received, to: $handling, roles: $desk, label: 'לקיחה לטיפול', assignsActor: true),
            // T2 / T3: a short request is closed straight from the queue.
            new Transition(from: $received, to: $completed, roles: $desk, label: 'סגירת פנייה - טופלה', direction: Transition::DIRECTION_CLOSE),
            new Transition(from: $received, to: $notDone, roles: $desk, label: 'סגירה ללא ביצוע', direction: Transition::DIRECTION_REJECT, commentRequired: true),
            // T4 / T5: closing a request in handling.
            new Transition(from: $handling, to: $completed, roles: $desk, label: 'סגירת פנייה - טופלה', direction: Transition::DIRECTION_CLOSE),
            new Transition(from: $handling, to: $notDone, roles: $desk, label: 'סגירה ללא ביצוע', direction: Transition::DIRECTION_REJECT, commentRequired: true),
            // T6: back to the queue - the handler is released, the comment is optional (the sheet: שדה להערות, לא שדה חובה).
            new Transition(from: $handling, to: $received, roles: $desk, label: 'החזרה לאחור - החזרה לתור', direction: Transition::DIRECTION_BACK, releasesHandler: true),
            // T7 / T8: the commander reopens a closed request.
            new Transition(from: $completed, to: $handling, roles: $commander, label: 'פתיחה מחדש', direction: Transition::DIRECTION_BACK, commentRequired: true),
            new Transition(from: $notDone, to: $handling, roles: $commander, label: 'פתיחה מחדש', direction: Transition::DIRECTION_BACK, commentRequired: true),
            // A1: the type's automation ran and succeeded (WorkflowAutomationService::advanceAutomated; nothing runs until config enables a type).
            new Transition(from: $received, to: $handling, roles: [], label: 'טופל אוטומטית', auto: Transition::AUTO_AUTOMATION),
        ];
    }

    public function transitionsFrom(int $statusId): array
    {
        $from = $this->normalize($statusId);

        return array_values(array_filter($this->transitions(), fn (Transition $transition): bool => $transition->from === $from));
    }

    public function assignment(): array
    {
        return [
            'statuses' => [Status::RECEIVED->value, Status::IN_SERVICE_DESK->value],
            'assigners' => [RoleEnum::ADMIN_ICT],
            'candidates' => [RoleEnum::VICE_APPROVER, RoleEnum::ADMIN_ICT],
            'self' => [RoleEnum::VICE_APPROVER],
        ];
    }

    /** The ICT forms have no handler section. */
    public function handlerFieldStatuses(): array
    {
        return [];
    }

    public function dueFields(): ?array
    {
        return null;
    }

    /** A status the route knows is itself; a closed one too; anything else (older data: 2, 3, 15, 16) counts as the queue. */
    public function normalize(int $statusId): int
    {
        $status = Status::tryFrom($statusId);

        if ($status === null) {
            return $this->initialStatus();
        }

        if (in_array($statusId, $this->statusIds(), true) || $status->isTerminal()) {
            return $statusId;
        }

        return $this->initialStatus();
    }
}

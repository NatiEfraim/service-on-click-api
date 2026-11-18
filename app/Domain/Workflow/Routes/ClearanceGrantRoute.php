<?php

namespace App\Domain\Workflow\Routes;

use App\Domain\Workflow\Transition;
use App\Domain\Workflow\TypeRoute;
use App\Enums\BookingStageEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum as Status;

/**
 * מתן סיווג ביטחוני (type 14), as the product's sheet and the legacy screen describe it:
 *
 *   קב"ט (DEPARTMENT_SECURITY_OFFICER) submits ──▶ 101 הקצאה לתחקיר
 *       במ (INFOSEC_OFFICER) assigns a handler, or a coordinator takes it; the coordinator enters place, date and time
 *   ──▶ 102 נקבע מול המועמד ── the interview moment passes (scheduler) ──▶ 103 תוחקר
 *   ──▶ 104 ממתין לקבלת הכשר ── ותיקן confirms the level (scheduler, when enabled) or במ confirms by hand ──▶ 105 קיבל הכשר ביטחוני
 *
 *   back:   102 / 103 / 104 ──▶ 101   במ only, comment required, the interview place / date / time are deleted
 *   refuse: 101 … 104 ──▶ 4           במ only, comment required
 *
 * The super admin holds every manual move through the synthetic admin_14 (Workflow::holdsRouteRole).
 */
final class ClearanceGrantRoute implements TypeRoute
{
    public function key(): string
    {
        return 'clearance_grant';
    }

    public function initialStatus(): int
    {
        return Status::INTERVIEW_ASSIGNMENT->value;
    }

    public function statusIds(): array
    {
        return [...$this->steps(), Status::REJECTED->value];
    }

    public function steps(): array
    {
        return [
            Status::INTERVIEW_ASSIGNMENT->value,
            Status::INTERVIEW_SCHEDULED->value,
            Status::INTERVIEWED->value,
            Status::AWAITING_CLEARANCE->value,
            Status::CLEARANCE_GRANTED->value,
        ];
    }

    public function terminalStatuses(): array
    {
        return [Status::CLEARANCE_GRANTED->value, Status::REJECTED->value];
    }

    public function successStatus(): int
    {
        return Status::CLEARANCE_GRANTED->value;
    }

    public function stageOf(int $statusId): BookingStageEnum
    {
        $status = Status::tryFrom($statusId);

        if ($status === null || $status->isTerminal()) {
            return BookingStageEnum::CLOSED;
        }

        return match ($status) {
            Status::INTERVIEW_SCHEDULED => BookingStageEnum::INTERVIEW_SCHEDULED,
            Status::INTERVIEWED => BookingStageEnum::INTERVIEWED,
            Status::AWAITING_CLEARANCE => BookingStageEnum::AWAITING_CLEARANCE,
            // 101, and any generic status older data left a request in: back at the start of the route.
            default => BookingStageEnum::INTERVIEW_ASSIGNMENT,
        };
    }

    public function roles(): array
    {
        return [RoleEnum::INFOSEC_OFFICER, RoleEnum::INTERVIEW_COORDINATOR];
    }

    public function ownerRole(): RoleEnum
    {
        return RoleEnum::INFOSEC_OFFICER;
    }

    public function requesterRole(): RoleEnum
    {
        return RoleEnum::DEPARTMENT_SECURITY_OFFICER;
    }

    public function transitions(): array
    {
        $officer = RoleEnum::INFOSEC_OFFICER;
        $coordinator = RoleEnum::INTERVIEW_COORDINATOR;
        $interview = ClearanceGrantFields::interviewFields();

        return [
            // T1: the interview is set with the candidate - needs a handler and the three interview fields.
            new Transition(from: Status::INTERVIEW_ASSIGNMENT->value, to: Status::INTERVIEW_SCHEDULED->value, roles: [$officer, $coordinator], label: 'נקבע מול המועמד', requiresHandler: true, requiredFields: $interview),
            // T2: the interview moment has passed (scheduler).
            new Transition(from: Status::INTERVIEW_SCHEDULED->value, to: Status::INTERVIEWED->value, roles: [], label: 'תוחקר', auto: Transition::AUTO_DUE),
            // T3: the interview was held; the request goes on to the clearance.
            new Transition(from: Status::INTERVIEWED->value, to: Status::AWAITING_CLEARANCE->value, roles: [$officer, $coordinator], label: 'התחקיר בוצע - העברה להכשר'),
            // T4: the registry shows the requested level (scheduler, when workflow.clearance.auto_close is on).
            new Transition(from: Status::AWAITING_CLEARANCE->value, to: Status::CLEARANCE_GRANTED->value, roles: [], label: 'קיבל הכשר ביטחוני', direction: Transition::DIRECTION_CLOSE, auto: Transition::AUTO_REGISTRY),
            // T5: במ confirms the clearance was recorded.
            new Transition(from: Status::AWAITING_CLEARANCE->value, to: Status::CLEARANCE_GRANTED->value, roles: [$officer], label: 'הסיווג נקלט בוותיקן - סגירת פנייה', direction: Transition::DIRECTION_CLOSE),
            // T6: back to the assignment - the interview details are deleted and the reason is required.
            ...array_map(
                fn (Status $from): Transition => new Transition(from: $from->value, to: Status::INTERVIEW_ASSIGNMENT->value, roles: [$officer], label: 'החזרה להקצאה לתחקיר', direction: Transition::DIRECTION_BACK, commentRequired: true, clearsFields: $interview),
                [Status::INTERVIEW_SCHEDULED, Status::INTERVIEWED, Status::AWAITING_CLEARANCE],
            ),
            // T7: the request is refused.
            ...array_map(
                fn (Status $from): Transition => new Transition(from: $from->value, to: Status::REJECTED->value, roles: [$officer], label: 'דחיית הבקשה', direction: Transition::DIRECTION_REJECT, commentRequired: true),
                [Status::INTERVIEW_ASSIGNMENT, Status::INTERVIEW_SCHEDULED, Status::INTERVIEWED, Status::AWAITING_CLEARANCE],
            ),
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
            'statuses' => [Status::INTERVIEW_ASSIGNMENT->value, Status::INTERVIEW_SCHEDULED->value, Status::INTERVIEWED->value, Status::AWAITING_CLEARANCE->value],
            'assigners' => [RoleEnum::INFOSEC_OFFICER],
            'candidates' => [RoleEnum::INTERVIEW_COORDINATOR, RoleEnum::INFOSEC_OFFICER],
            'self' => [RoleEnum::INTERVIEW_COORDINATOR],
        ];
    }

    public function handlerFieldStatuses(): array
    {
        $both = [RoleEnum::INFOSEC_OFFICER, RoleEnum::INTERVIEW_COORDINATOR];

        // Prepared while waiting for the assignment, rescheduled once set.
        return [
            Status::INTERVIEW_ASSIGNMENT->value => $both,
            Status::INTERVIEW_SCHEDULED->value => $both,
        ];
    }

    public function dueFields(): ?array
    {
        return ['date' => ClearanceGrantFields::INTERVIEW_DATE, 'time' => ClearanceGrantFields::INTERVIEW_TIME];
    }

    /** A status the route knows is itself; a closed one too; anything else (older data) counts as the start of the route. */
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

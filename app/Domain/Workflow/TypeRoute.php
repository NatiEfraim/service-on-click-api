<?php

namespace App\Domain\Workflow;

use App\Enums\BookingStageEnum;
use App\Enums\RoleEnum;

/**
 * The route of one booking type that does not follow the generic approver chain: its statuses, stages, roles and
 * transitions. Workflow asks for the type's route first and falls back to the generic rules when there is none, so
 * every other type is untouched. Status ids of a route are web only (from 101).
 */
interface TypeRoute
{
    /** A short key for configuration and payloads, e.g. "clearance_grant". */
    public function key(): string;

    /** The status a new request is created in. */
    public function initialStatus(): int;

    /** @return array<int, int> Every status the type can reach (what booking_type_has_statuses links). */
    public function statusIds(): array;

    /** @return array<int, int> The statuses of the progress bar, in order (the successful path). */
    public function steps(): array;

    /** @return array<int, int> The closed statuses: the successful end and the refusal. */
    public function terminalStatuses(): array;

    /** The status that counts as "done" (the successful end). */
    public function successStatus(): int;

    public function stageOf(int $statusId): BookingStageEnum;

    /** @return array<int, RoleEnum> The roles that act on the route (handlers); the requester role is not among them. */
    public function roles(): array;

    /** The role that owns the route: it gets the dashboard and may act everywhere a handler may. */
    public function ownerRole(): RoleEnum;

    /** The role that submits a request of this type. */
    public function requesterRole(): RoleEnum;

    /** @return array<int, Transition> */
    public function transitions(): array;

    /**
     * The transitions that leave this status. A status the route does not know (a request left in a generic status by
     * older data) is treated as the initial status, so nothing is stranded; a closed status has none.
     * @return array<int, Transition>
     */
    public function transitionsFrom(int $statusId): array;

    /**
     * How the handler (מטפל) is assigned: in which statuses, by which roles, who may be assigned, and which roles may take
     * an unassigned request for themselves.
     * @return array{statuses: array<int, int>, assigners: array<int, RoleEnum>, candidates: array<int, RoleEnum>, self: array<int, RoleEnum>}
     */
    public function assignment(): array;

    /** @return array<int, array<int, RoleEnum>> status id => the roles that may write the handler fields while the request is there */
    public function handlerFieldStatuses(): array;

    /**
     * The handler fields (a DATE and a TIME field name) that make the moment an automatic "due" transition waits for; null when the
     * route has no such transition. Stored on bookings.stage_due_at in UTC by the services.
     * @return ?array{date: string, time: string}
     */
    public function dueFields(): ?array;
}

<?php

namespace App\Domain\Workflow;

use App\Enums\RoleEnum;

/**
 * One move of a booking type's own route (TypeRoute): from a status to a status, by the roles that may press it (or by the
 * system, when $auto is set), with what the move needs and what it leaves behind. Pure data: the rules live in Workflow and
 * the services that apply them.
 */
final class Transition
{
    public const DIRECTION_FORWARD = 'forward';
    public const DIRECTION_BACK = 'back';
    public const DIRECTION_CLOSE = 'close';
    public const DIRECTION_REJECT = 'reject';

    /** The system moves the booking once bookings.stage_due_at has passed. */
    public const AUTO_DUE = 'due';
    /** The system moves the booking once the clearance registry (ותיקן) confirms the requested level. */
    public const AUTO_REGISTRY = 'registry';
    /** The system moves the booking once the type's automation (config workflow.ict.automation) reports success. */
    public const AUTO_AUTOMATION = 'automation';

    /**
     * @param array<int, RoleEnum> $roles roles that may press the move; [] = the system only (see $auto)
     * @param array<int, string> $requiredFields handler field names that must hold a value once the move is made
     * @param array<int, string> $clearsFields handler field names whose values are deleted by the move
     * @param ?string $auto null = manual; AUTO_DUE | AUTO_REGISTRY | AUTO_AUTOMATION = made by the scheduler
     * @param bool $assignsActor the one who presses the move becomes the handler when nobody is assigned yet ("take it")
     * @param bool $releasesHandler the move removes the assigned handler (back to the queue)
     */
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly array $roles,
        public readonly string $label,
        public readonly string $direction = self::DIRECTION_FORWARD,
        public readonly bool $commentRequired = false,
        public readonly bool $requiresHandler = false,
        public readonly array $requiredFields = [],
        public readonly array $clearsFields = [],
        public readonly ?string $auto = null,
        public readonly bool $assignsActor = false,
        public readonly bool $releasesHandler = false,
    ) {
    }

    public function isAutomatic(): bool
    {
        return $this->auto !== null;
    }

    public function isManual(): bool
    {
        return $this->auto === null;
    }

    /** @return array<int, string> the permission prefixes of the roles that may press it */
    public function permissionPrefixes(): array
    {
        return array_map(fn (RoleEnum $role): string => $role->toPermissionEnum()->value, $this->roles);
    }
}

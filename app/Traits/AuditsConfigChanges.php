<?php

namespace App\Traits;

use App\Exceptions\DomainError;
use App\Services\AuditService;
use App\Services\ConfigOutboxService;
use Illuminate\Support\Facades\Auth;

/** For the services that change what the booking types are configured with (references, options, statements): an audit row with the values before and after. */
trait AuditsConfigChanges
{
    /**
     * Record a change made by the logged-in person - inside the caller's transaction, so the change and its audit row stand or fall together.
     * Nothing is written when nothing changed.
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    protected function auditConfigChange(
        string $action,
        string $entityType,
        mixed $entityId,
        array $before,
        array $after,
        ?int $unitId = null,
        ?int $bookingTypeId = null,
    ): void {
        if ($before === $after) {
            return;
        }

        app(AuditService::class)->record([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'unit_id' => $unitId,
            'booking_type_id' => $bookingTypeId,
            'before' => $before,
            'after' => $after,
        ], Auth::user());
    }

    /**
     * Tell the mobile app about a change of the configuration: queued in the caller's transaction (see ConfigOutboxService for the entities).
     * @param array<string, mixed> $payload
     * @throws DomainError 422 when the change would carry the name of a unit or a department - the caller must let it through to roll back
     */
    protected function queueConfigChange(string $entity, string $op, array $payload): void
    {
        app(ConfigOutboxService::class)->queue($entity, $op, $payload);
    }

    /**
     * The changes of one request, as one file.
     * @param array<int, array<string, mixed>> $changes each with entity and op
     * @throws DomainError 422
     */
    protected function queueConfigChanges(array $changes): void
    {
        if ($changes !== []) {
            app(ConfigOutboxService::class)->queueMany($changes);
        }
    }
}

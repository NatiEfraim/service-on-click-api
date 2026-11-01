<?php

namespace App\Traits;

use App\Exceptions\DomainError;
use App\Services\AuditService;
use App\Services\ConfigOutboxService;
use Illuminate\Support\Facades\Auth;

/** Audit and outbox helpers for services that change booking type configuration. */
trait AuditsConfigChanges
{
    /**
     * Audits a change by the logged-in person, inside the caller's transaction. Skipped when nothing changed.
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
     * Queues a configuration change for the mobile app, inside the caller's transaction.
     * @param array<string, mixed> $payload
     * @throws DomainError 422 when the payload would carry a unit or department name; let it roll back
     */
    protected function queueConfigChange(string $entity, string $op, array $payload): void
    {
        app(ConfigOutboxService::class)->queue($entity, $op, $payload);
    }

    /**
     * Queues the changes of one request as one file.
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

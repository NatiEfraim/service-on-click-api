<?php

namespace App\Services;

use App\Domain\Sync\ExportFileNames;
use App\Domain\Sync\PayloadGuard;
use App\Exceptions\DomainError;
use App\Models\Department;
use App\Models\SyncFile;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * Queues configuration changes (form fields, options, statements, settings) as one {"changes": [...]} file per request,
 * in the transaction of the change; BookingStatusExportService sends the files oldest first.
 * Entities: form_field, option_field, system_reference, statement, unit. Payloads may not name a unit or a department (PayloadGuard).
 */
class ConfigOutboxService
{
    /**
     * Queues one change.
     * @param array<string, mixed> $payload everything except entity, op, correlation_id and changed_at
     * @throws DomainError 422 when the change names a unit or a department
     */
    public function queue(string $entity, string $op, array $payload, ?string $correlationId = null): SyncFile
    {
        return $this->queueMany([['entity' => $entity, 'op' => $op, ...$payload]], $correlationId);
    }

    /**
     * Queues several changes of one request as one file, in order.
     * @param array<int, array<string, mixed>> $changes each with entity and op
     */
    public function queueMany(array $changes, ?string $correlationId = null): SyncFile
    {
        $correlationId ??= (string) Str::uuid();
        $stamped = array_map(fn (array $change): array => [...$change, 'correlation_id' => $correlationId, 'changed_at' => now()->getTimestamp()], $changes);

        $this->assertExportable($stamped);

        $json = json_encode(['changes' => $stamped], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $file = SyncFile::query()->create([
            'direction' => 'outbound',
            'kind' => SyncFile::KIND_SYSTEM_CONFIG,
            // The final name carries the id (the change order), known only now.
            'file_name' => 'pending',
            'sha256' => hash('sha256', $json),
            'state' => config('app.export_json.config_channel_enabled', true) ? SyncFile::STATE_QUEUED : SyncFile::STATE_AWAITING_SUPPORT,
            'record_count' => count($stamped),
            'payload' => $json,
        ]);

        $file->update(['file_name' => ExportFileNames::systemConfigFile(now(config('app.export_json.timezone')), (int) $file->id)]);

        return $file;
    }

    /** Refuses a payload that names a unit or a department. */
    public function assertExportable(mixed $payload): void
    {
        if ($this->violations($payload) !== []) {
            throw new DomainError('הטקסט מכיל שם פנימי של יחידה או מחלקה, והשינוי הזה מועבר לאפליקציה. יש להשתמש באות של היחידה בלבד.', 422);
        }
    }

    /** @return string[] where the payload holds something that must not leave */
    public function violations(mixed $payload): array
    {
        return PayloadGuard::violations($payload, $this->internalNames());
    }

    /** @return string[] unit display names and department names, which the mobile app must never be told */
    public function internalNames(): array
    {
        return PayloadGuard::usableNames(
            [...Unit::query()->whereNotNull('internal_name')->pluck('internal_name')->all(), ...Department::query()->pluck('name')->all()],
            Unit::query()->pluck('name')->all(),
        );
    }
}

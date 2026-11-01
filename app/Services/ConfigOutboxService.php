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
 * The changes of the configuration on their way to the mobile app: the form structure, the options, the statements and the settings of the booking
 * types. Each request that changes something queues ONE file {"changes": [...]} (the same transaction as the change, so a change that is rolled
 * back sends nothing, and one that stays is never lost); BookingStatusExportService writes the files to the bucket, oldest first.
 *
 * A change is {entity, op, unit_id, booking_type_id, ...what it is about, data, correlation_id, changed_at}. The entities and their ops:
 *   form_field       create | update | reorder | archive | delete     field_key = the field_name (the same in both systems; the ids are not)
 *   option_field     create | update | archive | delete               field_key + option_path (the names from the top option down)
 *   system_reference update                                          key = the reference name, data = its whole value
 *   statement        update                                          data = {statement, is_active, show_time}
 *   unit             create                                          data = {unit_id, code} - the letter only
 * Nothing in a file may name a unit or a department (PayloadGuard) - the text is refused when it is saved, and checked again when it is sent.
 */
class ConfigOutboxService
{
    /**
     * Queue one change.
     * @param array<string, mixed> $payload everything except entity, op, correlation_id and changed_at
     * @throws DomainError 422 when the change would carry the name of a unit or a department
     */
    public function queue(string $entity, string $op, array $payload, ?string $correlationId = null): SyncFile
    {
        return $this->queueMany([['entity' => $entity, 'op' => $op, ...$payload]], $correlationId);
    }

    /**
     * Queue several changes of one request as one file, in the order given.
     * @param array<int, array<string, mixed>> $changes each with entity and op
     * @throws DomainError 422
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
            // The final name carries the id (the order of the changes) - known only now.
            'file_name' => 'pending',
            'sha256' => hash('sha256', $json),
            'state' => config('app.export_json.config_channel_enabled', true) ? SyncFile::STATE_QUEUED : SyncFile::STATE_AWAITING_SUPPORT,
            'record_count' => count($stamped),
            'payload' => $json,
        ]);

        $file->update(['file_name' => ExportFileNames::systemConfigFile(now(config('app.export_json.timezone')), (int) $file->id)]);

        return $file;
    }

    /**
     * Refuse a payload that names a unit or a department.
     * @throws DomainError 422
     */
    public function assertExportable(mixed $payload): void
    {
        if ($this->violations($payload) !== []) {
            throw new DomainError('הטקסט מכיל שם פנימי של יחידה או מחלקה, והשינוי הזה מועבר לאפליקציה. יש להשתמש באות של היחידה בלבד.', 422);
        }
    }

    /** Where the payload holds something that must not leave. @return string[] */
    public function violations(mixed $payload): array
    {
        return PayloadGuard::violations($payload, $this->internalNames());
    }

    /** The display names of the units and the names of the departments - what the mobile app must never be told. @return string[] */
    public function internalNames(): array
    {
        return PayloadGuard::usableNames(
            [...Unit::query()->whereNotNull('internal_name')->pluck('internal_name')->all(), ...Department::query()->pluck('name')->all()],
            Unit::query()->pluck('name')->all(),
        );
    }
}

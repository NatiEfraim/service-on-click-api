<?php

namespace App\Services;

use App\Domain\Audit\HashChain;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the audit log. A row is appended inside the caller's transaction, so a change and its audit row stand or fall together;
 * the head row is locked while it is written, so the hash chain stays one line however many requests come at once.
 *
 * A refusal is recorded with denied(), which must be called OUTSIDE the transaction the refusal will roll back - otherwise the row goes with it.
 */
class AuditService
{
    /**
     * Append a row. $event needs action and entity_type; everything else is optional: entity_id, booking_serial, unit_id, booking_type_id,
     * before, after, reason, result (success | denied | failed), correlation_id, source, actor_type, actor_id, actor_name.
     * @param array<string, mixed> $event
     */
    public function record(array $event, ?User $actor = null): AuditLog
    {
        return DB::transaction(function () use ($event, $actor): AuditLog {
            $head = DB::table('audit_log_head')->where('id', 1)->lockForUpdate()->first();

            if ($head === null) {
                DB::table('audit_log_head')->insertOrIgnore(['id' => 1]);
                $head = DB::table('audit_log_head')->where('id', 1)->lockForUpdate()->first();
            }

            $previousHash = $head->last_hash;

            $row = [
                'occurred_at' => now()->format('Y-m-d H:i:s.u'),
                'actor_type' => $event['actor_type'] ?? ($actor ? 'user' : 'system'),
                'actor_id' => $event['actor_id'] ?? $actor?->actorId(),
                'actor_name' => $event['actor_name'] ?? $actor?->full_name,
                'source' => $event['source'] ?? 'internal',
                'action' => $event['action'],
                'entity_type' => $event['entity_type'],
                'entity_id' => isset($event['entity_id']) ? (string) $event['entity_id'] : null,
                'booking_serial' => isset($event['booking_serial']) ? (int) $event['booking_serial'] : null,
                'unit_id' => isset($event['unit_id']) ? (int) $event['unit_id'] : null,
                'booking_type_id' => isset($event['booking_type_id']) ? (int) $event['booking_type_id'] : null,
                'before' => isset($event['before']) ? HashChain::redact((array) $event['before']) : null,
                'after' => isset($event['after']) ? HashChain::redact((array) $event['after']) : null,
                'reason' => isset($event['reason']) ? mb_substr((string) $event['reason'], 0, 500) : null,
                'result' => $event['result'] ?? 'success',
                'correlation_id' => $event['correlation_id'] ?? null,
                'prev_hash' => $previousHash,
            ];

            $row['hash'] = HashChain::hash($row, $previousHash);

            $log = AuditLog::query()->create($row);

            DB::table('audit_log_head')->where('id', 1)->update(['last_id' => $log->id, 'last_hash' => $row['hash']]);

            return $log;
        });
    }

    /** A refused or failed attempt at something sensitive. Never throws: a failure to write the row must not hide the refusal it describes. @param array<string, mixed> $context */
    public function denied(string $action, string $entityType, mixed $entityId, string $reason, ?User $actor, array $context = []): void
    {
        try {
            $this->record([
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'reason' => $reason,
                'result' => 'denied',
                ...$context,
            ], $actor);
        } catch (\Throwable) {
            // Nothing to do: the caller is already refusing the request.
        }
    }

    /**
     * One page of the log, newest first. Filters (all optional): serial (booking), actor (the actor's personal number), action (a prefix such as
     * "booking." or "config.sla_target"), source, type (booking type id), result, from / to (Y-m-d), page. The automatic transfer events of the
     * sync ("sync.outbound.*" written by the schedule) are in the log but not shown here.
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{total: int, page: int, last_page: int, per_page: int}}
     */
    public function search(array $filters, int $perPage = 40): array
    {
        $page = AuditLog::query()
            // The machine's transfer events (files sent, failed, retried by the schedule) are not listed; what a person did, and the
            // conflicts - found, settled by a person or by the import itself - are.
            ->where(fn ($query) => $query->where('action', 'not like', 'sync.%')->orWhere('action', 'like', 'sync.conflict.%')->orWhere('actor_type', 'user'))
            ->when($filters['serial'] ?? null, fn ($query, $value) => $query->where('booking_serial', (int) $value))
            ->when($filters['actor'] ?? null, fn ($query, $value) => $query->where('actor_id', $value))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', 'like', addcslashes((string) $value, '%_\\') . '%'))
            ->when($filters['source'] ?? null, fn ($query, $value) => $query->where('source', $value))
            ->when($filters['type'] ?? null, fn ($query, $value) => $query->where('booking_type_id', (int) $value))
            ->when($filters['result'] ?? null, fn ($query, $value) => $query->where('result', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('occurred_at', '>=', $value . ' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('occurred_at', '<=', $value . ' 23:59:59.999999'))
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        return [
            'data' => $page->items() ? array_map(fn (AuditLog $row): array => $row->toArray(), $page->items()) : [],
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $perPage],
        ];
    }

    /**
     * Walk the whole log and check the chain, then check that it ends where the head row says it does (rows removed from the end).
     * @return array{valid: bool, checked: int, broken_at: ?int}
     */
    public function verify(): array
    {
        $result = HashChain::verify($this->rowsInOrder());

        if (!$result['valid']) {
            return $result;
        }

        $head = DB::table('audit_log_head')->where('id', 1)->first();
        $last = AuditLog::query()->orderByDesc('id')->first(['id', 'hash']);

        if ($head !== null && $head->last_hash !== null && ($last === null || (int) $head->last_id !== (int) $last->id || $head->last_hash !== $last->hash)) {
            return ['valid' => false, 'checked' => $result['checked'], 'broken_at' => $last?->id ?? (int) $head->last_id];
        }

        return $result;
    }

    /** The whole log, oldest first, a couple of thousand rows at a time (a buffered cursor would hold the entire table in memory). @return \Generator<int, array<string, mixed>> */
    private function rowsInOrder(): \Generator
    {
        $lastId = 0;

        while (($rows = AuditLog::query()->where('id', '>', $lastId)->orderBy('id')->limit(2000)->get())->isNotEmpty()) {
            foreach ($rows as $row) {
                yield $row->getAttributes();
            }

            $lastId = (int) $rows->last()->id;
        }
    }
}

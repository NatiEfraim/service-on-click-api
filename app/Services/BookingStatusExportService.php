<?php

namespace App\Services;

use App\Domain\Sync\ExportFileNames;
use App\Exceptions\DomainError;
use App\Models\ApplicationLog;
use App\Models\Booking;
use App\Models\SyncConflict;
use App\Models\SyncFile;
use App\Support\AccessScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends the decisions made here back to the mobile app as r2b files in the bucket (export-json/...):
 *  - booking_status: a cumulative daily snapshot of unconfirmed decisions (the mobile importer reads one file per day by exact name);
 *  - booking_values: corrected date/time values, one file per correction, in order (queued by BookingValueService);
 *  - system_config: configuration changes, one file per request, in order (queued by ConfigOutboxService).
 * Bookings with an open sync conflict are held back; failed files retry with a growing wait; every payload passes PayloadGuard.
 */
class BookingStatusExportService
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly AuditService $audit,
        private readonly ConfigOutboxService $configOutbox,
    ) {
    }

    /** Sends what is waiting. @return array{enabled: bool, busy?: bool, status?: array<string, mixed>, values?: array<string, mixed>, config?: array<string, mixed>} */
    public function run(): array
    {
        if (!config('app.export_json.enabled', true)) {
            return ['enabled' => false];
        }

        // The scheduler and a manual "send now" may overlap: only one writes at a time.
        $lock = Cache::lock('export-json', 300);

        if (!$lock->get()) {
            return ['enabled' => true, 'busy' => true];
        }

        try {
            return ['enabled' => true, 'status' => $this->exportStatus(), 'values' => $this->exportValues(), 'config' => $this->exportConfig()];
        } finally {
            $lock->release();
        }
    }

    /** Writes today's snapshot of the unconfirmed decisions. @return array{sent: bool, reason?: string, file_id?: int, error?: string} */
    public function exportStatus(): array
    {
        $decisions = $this->unconfirmedDecisions();

        if ($decisions->isEmpty()) {
            return ['sent' => false, 'reason' => 'no_decisions'];
        }

        // The mobile importer reads the file of its own "today", so the day is counted in its timezone.
        $fileName = ExportFileNames::bookingStatusFile(now(config('app.export_json.timezone')), (string) config('app.export_json.booking_status_prefix'));
        $hasNew = $decisions->contains(fn (Booking $booking): bool => !$booking->is_status_exported);
        $sentToday = SyncFile::query()
            ->where('direction', 'outbound')->where('kind', SyncFile::KIND_BOOKING_STATUS)
            ->where('file_name', $fileName)->where('state', SyncFile::STATE_SENT)
            ->exists();

        // Nothing new and today's file is out already; it still holds every unconfirmed decision.
        if (!$hasNew && $sentToday) {
            return ['sent' => false, 'reason' => 'nothing_new'];
        }

        $deciders = $this->deciders($decisions->modelKeys());

        $json = json_encode(['bookings' => $decisions->map(fn (Booking $booking): array => [
            'serial_number' => (int) $booking->serial_number,
            'status_id' => (int) $booking->status_id,
            'personal_id' => isset($deciders[$booking->id]) ? preg_replace('/\D/', '', $deciders[$booking->id]) : null,
            'updated_at' => (string) $booking->statusSince()->getTimestamp(),
        ])->values()->all()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        // The day in the name identifies the snapshot: decisions still waiting tomorrow go in a new file.
        $file = SyncFile::query()->firstOrCreate(
            ['direction' => 'outbound', 'sha256' => hash('sha256', $fileName . "\n" . $json)],
            ['kind' => SyncFile::KIND_BOOKING_STATUS, 'file_name' => $fileName, 'state' => SyncFile::STATE_QUEUED, 'record_count' => $decisions->count(), 'payload' => $json]
        );

        // The same snapshot failed before: wait out the back-off; after the last attempt a person retries.
        if ($file->state === SyncFile::STATE_FAILED) {
            if ($file->next_attempt_at === null) {
                return ['sent' => false, 'reason' => 'needs_manual_retry', 'file_id' => $file->id];
            }

            if ($file->next_attempt_at->isFuture()) {
                return ['sent' => false, 'reason' => 'waiting_to_retry', 'file_id' => $file->id];
            }
        }

        if ($file->state !== SyncFile::STATE_SENT) {
            $result = $this->attempt($file);

            if (!$result['sent']) {
                return $result;
            }

            // An older snapshot that never made it is replaced by this one, which holds every waiting decision.
            SyncFile::query()
                ->where('direction', 'outbound')->where('kind', SyncFile::KIND_BOOKING_STATUS)
                ->whereIn('state', [SyncFile::STATE_QUEUED, SyncFile::STATE_FAILED])->where('id', '!=', $file->id)
                ->update(['state' => SyncFile::STATE_SUPERSEDED, 'next_attempt_at' => null]);
        }

        // A booking changed while the file was written has a newer version than the file holds:
        // it stays pending and goes in the next file.
        foreach ($decisions->reject(fn (Booking $booking): bool => $booking->is_status_exported) as $booking) {
            Booking::query()->whereKey($booking->id)->where('version', $booking->version)->toBase()->update(['is_status_exported' => true]);
        }

        return ['sent' => true, 'file_id' => $file->id];
    }

    /**
     * Sends queued value corrections, oldest first. A failed file and the later files of its bookings wait out the back-off
     * (or a person's retry), and the first write failure ends the round (the bucket is probably down).
     * @return array{sent: int}
     */
    public function exportValues(): array
    {
        $files = SyncFile::query()
            ->where('direction', 'outbound')->where('kind', SyncFile::KIND_BOOKING_VALUES)
            ->whereIn('state', [SyncFile::STATE_QUEUED, SyncFile::STATE_FAILED])
            ->orderBy('id')
            ->limit(50)
            ->get();

        $sent = 0;
        $held = [];

        foreach ($files as $file) {
            $serials = $this->serialsOf($file);
            $waiting = $file->state === SyncFile::STATE_FAILED && ($file->next_attempt_at === null || $file->next_attempt_at->isFuture());

            if ($waiting || array_intersect($serials, $held) !== []) {
                $held = [...$held, ...$serials];
                continue;
            }

            if (!$this->attempt($file)['sent']) {
                break;
            }

            $this->clearUpdatedValueFlags($file);
            $sent++;
        }

        return ['sent' => $sent];
    }

    /**
     * Sends queued configuration changes, oldest first, stopping at the first that cannot go (waiting to retry or blocked),
     * so a later change never reaches the mobile app before an earlier one.
     * @return array{sent: int, stopped_at?: int, reason?: string, error?: string} reason: waiting_to_retry | needs_manual_retry | blocked | failed
     */
    public function exportConfig(): array
    {
        // Changes kept while the channel was off go out once it is switched on.
        if (config('app.export_json.config_channel_enabled', true)) {
            SyncFile::query()
                ->where('direction', 'outbound')->where('kind', SyncFile::KIND_SYSTEM_CONFIG)->where('state', SyncFile::STATE_AWAITING_SUPPORT)
                ->update(['state' => SyncFile::STATE_QUEUED]);
        }

        $files = SyncFile::query()
            ->where('direction', 'outbound')->where('kind', SyncFile::KIND_SYSTEM_CONFIG)
            ->whereIn('state', [SyncFile::STATE_QUEUED, SyncFile::STATE_FAILED, SyncFile::STATE_BLOCKED])
            ->orderBy('id')
            ->limit(50)
            ->get();

        $sent = 0;

        foreach ($files as $file) {
            if ($file->state === SyncFile::STATE_FAILED && $file->next_attempt_at === null) {
                // Out of attempts: a person retries it (POST /sync/files/{id}/retry).
                return ['sent' => $sent, 'stopped_at' => (int) $file->id, 'reason' => 'needs_manual_retry'];
            }

            if ($file->state === SyncFile::STATE_FAILED && $file->next_attempt_at->isFuture()) {
                return ['sent' => $sent, 'stopped_at' => (int) $file->id, 'reason' => 'waiting_to_retry'];
            }

            $result = $this->attempt($file);

            if (!$result['sent']) {
                return ['sent' => $sent, 'stopped_at' => (int) $file->id, 'reason' => $file->fresh()->state === SyncFile::STATE_BLOCKED ? 'blocked' : 'failed', 'error' => $result['error'] ?? ''];
            }

            $sent++;
        }

        return ['sent' => $sent];
    }

    /** Writes one file to the bucket; a failure is kept on the file with the next try time. @return array{sent: bool, file_id?: int, error?: string} */
    public function attempt(SyncFile $file): array
    {
        // What leaves must not name a unit or a department.
        $leaks = $this->configOutbox->violations(json_decode((string) $file->payload, true));

        if ($leaks !== []) {
            return $this->block($file, $leaks);
        }

        $file->increment('attempts');

        try {
            $directory = trim((string) config('filesystems.folder_name.export_json.base'), '/') . '/' . config('filesystems.folder_name.export_json.' . match ($file->kind) {
                SyncFile::KIND_BOOKING_STATUS => 'booking_status',
                SyncFile::KIND_SYSTEM_CONFIG => 'system_config',
                default => 'booking_values',
            });

            $this->storageService->putJsonToBucket($file->file_name, $directory, (string) $file->payload);

            $file->update(['state' => SyncFile::STATE_SENT, 'last_error' => null, 'completed_at' => now(), 'next_attempt_at' => null]);
            $this->audit->record([
                'actor_type' => 'system', 'actor_id' => 'sync:export', 'source' => 'automation', 'action' => 'sync.outbound.sent',
                'entity_type' => 'sync_file', 'entity_id' => $file->id,
                'after' => ['file' => $file->file_name, 'records' => $file->record_count, 'attempt' => $file->attempts],
            ]);

            return ['sent' => true, 'file_id' => $file->id];
        } catch (\Throwable $e) {
            $maxAttempts = (int) config('app.export_json.max_attempts', 8);
            // 2, 4, 8 ... minutes, at most an hour.
            $delay = min(3600, 60 * (2 ** min((int) $file->attempts, 6)));
            // A signed URL in the message would leak its signature.
            $error = mb_substr((string) preg_replace('/\?\S+/', '?<redacted>', $e->getMessage()), 0, 500);

            $file->update([
                'state' => SyncFile::STATE_FAILED,
                'last_error' => $error,
                'next_attempt_at' => $file->attempts >= $maxAttempts ? null : now()->addSeconds($delay),
            ]);

            Log::error("BookingStatusExportService: {$file->file_name} failed (attempt {$file->attempts}): {$error}");
            $this->audit->record([
                'actor_type' => 'system', 'actor_id' => 'sync:export', 'source' => 'automation', 'action' => 'sync.outbound.failed',
                'entity_type' => 'sync_file', 'entity_id' => $file->id, 'result' => 'failed', 'reason' => $error,
            ]);

            return ['sent' => false, 'file_id' => $file->id, 'error' => $error];
        }
    }

    /**
     * Retries a failed file by hand.
     * @return array{sent: bool, file_id?: int, error?: string}
     */
    public function retry(AccessScope $scope, int $fileId): array
    {
        if (!$scope->can('sync.operate')) {
            $this->audit->denied('sync.outbound.retry', 'sync_file', $fileId, 'no_permission', $scope->user);

            throw new DomainError('אין לך הרשאה לשליחה חוזרת', 403);
        }

        $file = SyncFile::query()->where('direction', 'outbound')->findOrFail($fileId);

        if ($file->state === SyncFile::STATE_SENT) {
            throw new DomainError('הקובץ כבר נשלח בהצלחה', 409);
        }

        if ($file->state === SyncFile::STATE_SUPERSEDED) {
            throw new DomainError('הקובץ הוחלף בקובץ עדכני יותר', 409);
        }

        // A change kept while the config channel was off goes out when it is switched on, not by hand.
        if ($file->state === SyncFile::STATE_AWAITING_SUPPORT && !config('app.export_json.config_channel_enabled', true)) {
            throw new DomainError('ערוץ ההגדרות כבוי', 409);
        }

        $this->audit->record(['action' => 'sync.outbound.manual_retry', 'entity_type' => 'sync_file', 'entity_id' => $fileId], $scope->user);

        return $this->attempt($file);
    }

    /**
     * Keeps back a file that names a unit or a department until the name is gone or a person resends it;
     * the audit log gets one row when it becomes blocked, not one per round.
     * @param string[] $leaks where in the payload the names were found
     * @return array{sent: bool, file_id: int, error: string}
     */
    private function block(SyncFile $file, array $leaks): array
    {
        $error = 'internal name in ' . implode(', ', array_slice($leaks, 0, 5));

        if ($file->state !== SyncFile::STATE_BLOCKED) {
            $file->update(['state' => SyncFile::STATE_BLOCKED, 'last_error' => $error, 'next_attempt_at' => null]);

            Log::warning("BookingStatusExportService: {$file->file_name} is blocked: {$error}");
            $this->audit->record([
                'actor_type' => 'system', 'actor_id' => 'sync:export', 'source' => 'automation', 'action' => 'sync.outbound.blocked',
                'entity_type' => 'sync_file', 'entity_id' => $file->id, 'result' => 'denied', 'reason' => $error,
            ]);
        }

        return ['sent' => false, 'file_id' => $file->id, 'error' => $error];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Booking> decisions not confirmed by the mobile app and not held by an open conflict */
    private function unconfirmedDecisions(): \Illuminate\Database\Eloquent\Collection
    {
        return Booking::query()
            ->whereNotNull('serial_number')
            ->where('status_source', 'internal')
            ->where(fn ($query) => $query->whereNull('ext_status_id')->orWhereColumn('ext_status_id', '!=', 'status_id'))
            ->whereDoesntHave('syncConflicts', fn ($conflicts) => $conflicts->where('state', SyncConflict::STATE_OPEN))
            ->orderBy('serial_number')
            ->get();
    }

    /** Personal ID of whoever made the latest decision on each booking. @return array<int, ?string> booking id => personal_id */
    private function deciders(array $bookingIds): array
    {
        $deciders = [];

        $logs = ApplicationLog::query()
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('source', ['internal', 'system'])
            ->with('user:id,personal_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'booking_id', 'user_id', 'created_at']);

        foreach ($logs as $log) {
            $deciders[$log->booking_id] ??= $log->user?->personal_id;
        }

        return $deciders;
    }

    /** @return array<int, int|string> serial numbers of the bookings a corrections file covers */
    private function serialsOf(SyncFile $file): array
    {
        return array_values(array_unique(array_column(json_decode((string) $file->payload, true)['changes'] ?? [], 'serial_number')));
    }

    /** Clears the pending flag of a sent corrections file's bookings unless a later correction is still queued. */
    private function clearUpdatedValueFlags(SyncFile $file): void
    {
        $serials = $this->serialsOf($file);

        $stillQueued = SyncFile::query()
            ->where('direction', 'outbound')->where('kind', SyncFile::KIND_BOOKING_VALUES)
            ->whereIn('state', [SyncFile::STATE_QUEUED, SyncFile::STATE_FAILED])
            ->get(['payload'])
            ->flatMap(fn (SyncFile $queued): array => $this->serialsOf($queued))
            ->all();

        $done = array_values(array_diff($serials, $stillQueued));

        if ($done !== []) {
            Booking::query()->whereIn('serial_number', $done)->toBase()->update(['is_updated_value' => false]);
        }
    }
}

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
 * Sends the decisions made here back to the mobile app, as r2b files in the bucket (export-json/...):
 *  - booking_status: the decisions the mobile app has not confirmed yet - the status here differs from the last one seen from it. The mobile
 *    importer reads ONE file per day by its exact name and skips the rows that did not change, so each run writes a cumulative snapshot under
 *    today's name: sending it again is harmless, and a decision that waited for days (the bucket was down) is in it too. A decision stops being
 *    in the snapshot once the mobile app shows the same status in a file it sends us (ImportBookingService: "confirm").
 *  - booking_values: the corrected date / time values, one file per correction, in the order they were made (queued by BookingValueService).
 *  - system_config: the changes of the configuration, one file per request, in the order they were made (queued by ConfigOutboxService).
 * A booking with an open sync conflict is held back until a person decides. A file that cannot be written is tried again with a growing wait.
 * Every file is checked before it is written: one that holds the name of a unit or a department is blocked, not sent (PayloadGuard).
 */
class BookingStatusExportService
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly AuditService $audit,
        private readonly ConfigOutboxService $configOutbox,
    ) {
    }

    /** Send what is waiting. @return array{enabled: bool, busy?: bool, status?: array<string, mixed>, values?: array<string, mixed>, config?: array<string, mixed>} */
    public function run(): array
    {
        if (!config('app.export_json.enabled', true)) {
            return ['enabled' => false];
        }

        // The scheduler and a person pressing "send now" may both come by: only one writes at a time.
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

    /** Write today's snapshot of the unconfirmed decisions. @return array{sent: bool, reason?: string, file_id?: int, error?: string} */
    public function exportStatus(): array
    {
        $decisions = $this->unconfirmedDecisions();

        if ($decisions->isEmpty()) {
            return ['sent' => false, 'reason' => 'no_decisions'];
        }

        // The mobile importer reads the file named for its "today": the day is counted in the timezone it uses.
        $fileName = ExportFileNames::bookingStatusFile(now(config('app.export_json.timezone')), (string) config('app.export_json.booking_status_prefix'));
        $hasNew = $decisions->contains(fn (Booking $booking): bool => !$booking->is_status_exported);
        $sentToday = SyncFile::query()
            ->where('direction', 'outbound')->where('kind', SyncFile::KIND_BOOKING_STATUS)
            ->where('file_name', $fileName)->where('state', SyncFile::STATE_SENT)
            ->exists();

        // Nothing new, and today's file is out already: it still holds every unconfirmed decision. (After midnight it is a new name.)
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

        // The file name (the day) is part of what identifies a snapshot: the same decisions still waiting tomorrow are a new file under tomorrow's
        // name - the mobile importer reads only the file of its "today" - not yesterday's file again.
        $file = SyncFile::query()->firstOrCreate(
            ['direction' => 'outbound', 'sha256' => hash('sha256', $fileName . "\n" . $json)],
            ['kind' => SyncFile::KIND_BOOKING_STATUS, 'file_name' => $fileName, 'state' => SyncFile::STATE_QUEUED, 'record_count' => $decisions->count(), 'payload' => $json]
        );

        // The same snapshot failed before: it waits out its back-off, and after the last attempt a person sends it (POST /sync/files/{id}/retry).
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

            // An older snapshot (of today or of an earlier day) that never made it is replaced by this one: it holds every decision still waiting.
            SyncFile::query()
                ->where('direction', 'outbound')->where('kind', SyncFile::KIND_BOOKING_STATUS)
                ->whereIn('state', [SyncFile::STATE_QUEUED, SyncFile::STATE_FAILED])->where('id', '!=', $file->id)
                ->update(['state' => SyncFile::STATE_SUPERSEDED, 'next_attempt_at' => null]);
        }

        // What is in the file is sent. A booking that was changed again while the file was written has a newer version than the file holds:
        // it stays pending and goes in the next one.
        foreach ($decisions->reject(fn (Booking $booking): bool => $booking->is_status_exported) as $booking) {
            Booking::query()->whereKey($booking->id)->where('version', $booking->version)->toBase()->update(['is_status_exported' => true]);
        }

        return ['sent' => true, 'file_id' => $file->id];
    }

    /**
     * Send the queued corrections of values, oldest first. A later correction never arrives before an earlier one of the same booking: a file
     * that failed waits out its back-off (after the last attempt, for a person to send it) and the later files of its bookings wait with it.
     * The first file that fails to be written ends the round - the bucket is probably down, there is no point in trying the others.
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
     * Send the queued changes of the configuration, oldest first, and stop at the first that cannot go: a later change must never reach the mobile
     * app before an earlier one (a field renamed, then removed). That is a file that failed and waits out its back-off, or one that is blocked.
     * @return array{sent: int, stopped_at?: int, reason?: string, error?: string} reason: waiting_to_retry | needs_manual_retry | blocked | failed
     */
    public function exportConfig(): array
    {
        // Switched on after changes were kept: they go out now.
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
                // Out of attempts: a person sends it (POST /sync/files/{id}/retry).
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

    /** Write one file to the bucket. A failure is kept on the file, with when to try again. @return array{sent: bool, file_id?: int, error?: string} */
    public function attempt(SyncFile $file): array
    {
        // Before anything is written: what leaves must not name a unit or a department.
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
     * Send a failed file again by hand (after the cause was fixed).
     * @return array{sent: bool, file_id?: int, error?: string}
     * @throws DomainError 403 not allowed, 404 no such file, 409 it was sent already or replaced
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

        // A change kept while the configuration channel was off goes out when it is switched on - not by hand before.
        if ($file->state === SyncFile::STATE_AWAITING_SUPPORT && !config('app.export_json.config_channel_enabled', true)) {
            throw new DomainError('ערוץ ההגדרות כבוי', 409);
        }

        $this->audit->record(['action' => 'sync.outbound.manual_retry', 'entity_type' => 'sync_file', 'entity_id' => $fileId], $scope->user);

        return $this->attempt($file);
    }

    /**
     * Keep a file back because it names a unit or a department. It stays blocked (and the changes after it wait) until the name is gone from the
     * file's content or a person sends it again by hand; the audit log gets one row when it becomes blocked, not one for every round.
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

    /** The decisions the mobile app has not confirmed, none held by an open conflict. @return \Illuminate\Database\Eloquent\Collection<int, Booking> */
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

    /** Who made the latest decision on each booking: the ID number the mobile app knows them by. @param array<int, int> $bookingIds @return array<int, ?string> booking id => personal_id */
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

    /** The serial numbers of the bookings a corrections file is about. @return array<int, int|string> */
    private function serialsOf(SyncFile $file): array
    {
        return array_values(array_unique(array_column(json_decode((string) $file->payload, true)['changes'] ?? [], 'serial_number')));
    }

    /** The bookings of a sent corrections file are no longer waiting to be sent - unless a later correction of theirs is still queued. */
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

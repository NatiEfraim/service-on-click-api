<?php

namespace App\Services;

use App\Domain\Sync\StatusConflictResolver;
use App\Enums\StatusBookingEnum;
use App\Exceptions\DomainError;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Status;
use App\Models\SyncConflict;
use App\Models\SyncFile;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the screen for the traffic with the mobile app shows - the files sent back, the decisions still waiting to be sent, the sync
 * conflicts - and what a person can do about it: settle a conflict, send now. Sending itself is BookingStatusExportService's.
 */
class SyncService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly BookingStatusExportService $export,
    ) {
    }

    /** @return array<string, mixed> health, files, conflicts, pending_decisions, can_operate */
    public function overview(AccessScope $scope): array
    {
        $this->need($scope, 'sync.view');

        $labels = Status::query()->pluck('display_name', 'id');
        $bookingTypes = BookingType::query()->pluck('display_name', 'id');

        return [
            'health' => $this->health(),
            // (Without the payload: it can be large, and the screen shows a file's content only when it is opened.)
            'files' => SyncFile::query()->orderByDesc('id')->limit(150)
                ->get(['id', 'kind', 'file_name', 'state', 'attempts', 'record_count', 'last_error', 'next_attempt_at', 'completed_at', 'created_at'])
                ->map(fn (SyncFile $file): array => $this->fileRow($file))->all(),
            'conflicts' => SyncConflict::query()->with('booking:id,serial_number,booking_type_id')->orderByDesc('id')->limit(50)->get()
                ->map(fn (SyncConflict $conflict): array => [
                    'id' => $conflict->id,
                    'booking_serial' => $conflict->booking?->serial_number,
                    'booking_type_label' => $conflict->booking ? ($bookingTypes[$conflict->booking->booking_type_id] ?? null) : null,
                    'internal_status_id' => $conflict->internal_status_id,
                    'internal_label' => $labels[$conflict->internal_status_id] ?? null,
                    'external_status_id' => $conflict->external_status_id,
                    'external_label' => $labels[$conflict->external_status_id] ?? null,
                    'state' => $conflict->state,
                    'resolution' => $conflict->resolution,
                    'detected_at' => $conflict->created_at?->toIso8601String(),
                    'resolved_at' => $conflict->resolved_at?->toIso8601String(),
                ])->all(),
            'pending_decisions' => Booking::query()
                ->where('status_source', 'internal')->where('is_status_exported', false)->whereNotNull('serial_number')
                ->orderBy('status_changed_at')->limit(200)->get(['id', 'serial_number', 'status_id', 'status_changed_at', 'updated_at'])
                ->map(fn (Booking $booking): array => [
                    'booking_serial' => $booking->serial_number,
                    'status_id' => (int) $booking->status_id,
                    'status_label' => $labels[$booking->status_id] ?? null,
                    'decided_at' => $booking->statusSince()->toIso8601String(),
                ])->all(),
            'can_operate' => $scope->can('sync.operate'),
        ];
    }

    /** One file and what it carries (the first 500 rows). @return array{file: array<string, mixed>, records: array<int, mixed>} */
    public function file(AccessScope $scope, int $fileId): array
    {
        $this->need($scope, 'sync.view');

        $file = SyncFile::query()->findOrFail($fileId);
        $payload = json_decode((string) $file->payload, true) ?: [];

        return ['file' => $this->fileRow($file), 'records' => array_slice($payload['bookings'] ?? $payload['changes'] ?? [], 0, 500)];
    }

    /** Send what is waiting now, instead of at the next round. @return array<string, mixed> @throws DomainError 403 */
    public function run(AccessScope $scope): array
    {
        $this->need($scope, 'sync.operate');

        return $this->export->run();
    }

    /**
     * Settle a conflict: keep the status the mobile app has (accept_external) or our decision (keep_internal). The reason is kept in the audit log.
     * @return array{resolved: bool, status_id: int}
     * @throws DomainError 403, 409 settled already, 422 the reason is missing or the requester's cancellation would be revived
     */
    public function resolveConflict(AccessScope $scope, int $conflictId, string $resolution, string $reason): array
    {
        $this->need($scope, 'sync.operate');

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainError('יש לנמק את ההחלטה', 422);
        }

        if (!in_array($resolution, [SyncConflict::ACCEPT_EXTERNAL, SyncConflict::KEEP_INTERNAL], true)) {
            throw new DomainError('החלטה לא מוכרת', 422);
        }

        return DB::transaction(function () use ($scope, $conflictId, $resolution, $reason): array {
            $conflict = SyncConflict::query()->lockForUpdate()->findOrFail($conflictId);

            if ($conflict->state !== SyncConflict::STATE_OPEN) {
                throw new DomainError('ההתנגשות כבר יושבה', 409);
            }

            $booking = Booking::query()->lockForUpdate()->findOrFail($conflict->booking_id);
            $correlationId = (string) Str::uuid();
            $before = ['internal_status_id' => (int) $conflict->internal_status_id, 'external_status_id' => (int) $conflict->external_status_id];

            if ($resolution === SyncConflict::ACCEPT_EXTERNAL) {
                $external = StatusBookingEnum::from((int) $conflict->external_status_id);

                $booking->forceFill([
                    'status_id' => $external->value,
                    'ext_status_id' => $external->value,
                    'status_source' => 'external',
                    'status_changed_at' => now(),
                    'version' => (int) $booking->version + 1,
                    // The mobile app has this status already: nothing is sent.
                    'is_status_exported' => true,
                ])->save();

                $this->log($booking, $external, $scope, 'system', $correlationId);

                // The status that arrived may give a barber slot back, as a decision made here would.
                if ($external->freesBarberSlot()) {
                    BarberBooking::query()->where('booking_id', $booking->id)->update(['is_deleted' => true]);
                }
            } else {
                if (!StatusConflictResolver::canKeepInternal((int) $conflict->external_status_id)) {
                    throw new DomainError('המזמין ביטל את הקריאה באפליקציה - לא ניתן להחזיר אותה לטיפול', 422);
                }

                $booking->forceFill([
                    'status_source' => 'internal',
                    'status_changed_at' => now(),
                    'version' => (int) $booking->version + 1,
                    // The decision is sent again, now that a person stands behind it.
                    'is_status_exported' => false,
                ])->save();

                $this->log($booking, $booking->statusEnum(), $scope, 'internal', $correlationId);
            }

            $conflict->update([
                'state' => SyncConflict::STATE_RESOLVED,
                'resolution' => $resolution,
                'resolved_by' => $scope->user->actorId(),
                'resolution_reason' => $reason,
                'resolved_at' => now(),
            ]);

            $this->audit->record([
                'action' => 'sync.conflict.resolve',
                'entity_type' => 'booking',
                'entity_id' => $booking->serial_number,
                'booking_serial' => $booking->serial_number,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
                'before' => $before,
                'after' => ['resolution' => $resolution, 'status_id' => (int) $booking->status_id],
                'reason' => $reason,
                'correlation_id' => $correlationId,
            ], $scope->user);

            return ['resolved' => true, 'status_id' => (int) $booking->status_id];
        });
    }

    /** @return array<string, mixed> */
    private function health(): array
    {
        return [
            'export_enabled' => (bool) config('app.export_json.enabled', true),
            'last_sent_at' => SyncFile::query()->where('state', SyncFile::STATE_SENT)->max('completed_at'),
            'pending_decisions' => Booking::query()->where('status_source', 'internal')->where('is_status_exported', false)->count(),
            'queued_value_files' => SyncFile::query()->where('kind', SyncFile::KIND_BOOKING_VALUES)->where('state', SyncFile::STATE_QUEUED)->count(),
            'config_channel_enabled' => (bool) config('app.export_json.config_channel_enabled', true),
            'queued_config_files' => SyncFile::query()->where('kind', SyncFile::KIND_SYSTEM_CONFIG)->whereIn('state', [SyncFile::STATE_QUEUED, SyncFile::STATE_AWAITING_SUPPORT])->count(),
            'blocked_files' => SyncFile::query()->where('state', SyncFile::STATE_BLOCKED)->count(),
            'failed_files' => SyncFile::query()->where('state', SyncFile::STATE_FAILED)->count(),
            'open_conflicts' => SyncConflict::query()->where('state', SyncConflict::STATE_OPEN)->count(),
        ];
    }

    /** A file as the screen shows it - without the payload. @return array<string, mixed> */
    private function fileRow(SyncFile $file): array
    {
        return [
            'id' => $file->id,
            'kind' => $file->kind,
            'file_name' => $file->file_name,
            'state' => $file->state,
            'attempts' => $file->attempts,
            'record_count' => $file->record_count,
            'last_error' => $file->last_error,
            'next_attempt_at' => $file->next_attempt_at?->toIso8601String(),
            'completed_at' => $file->completed_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }

    private function log(Booking $booking, StatusBookingEnum $status, AccessScope $scope, string $source, string $correlationId): void
    {
        app(BookingStatusRecorder::class)->log($booking, $status, $scope->user, $source, $correlationId);
    }

    /** @throws DomainError 403 */
    private function need(AccessScope $scope, string $capability): void
    {
        if (!$scope->can($capability)) {
            $this->audit->denied("sync.{$capability}", 'sync', null, 'no_permission', $scope->user);

            throw new DomainError('אין לך הרשאה למידע זה', 403);
        }
    }
}

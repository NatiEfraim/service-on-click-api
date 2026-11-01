<?php

namespace App\Services;

use App\Exceptions\DomainError;
use App\Models\SlaTarget;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * The handling target of a booking type: total hours from opening to closing; an older booking is overdue.
 * The super admin sets any type's target; an admin (camp commander) only the types they administer.
 */
class SlaTargetService
{
    public const MIN_HOURS = 1;

    /** 180 days. */
    public const MAX_HOURS = 24 * 180;

    public function __construct(private readonly AuditService $audit)
    {
    }

    /** Targets of the booking types the user may see. @return array<int, array{booking_type_id: int, target_hours: float, warn_ratio: float, set_by: ?string}> */
    public function list(AccessScope $scope): array
    {
        return SlaTarget::query()
            ->where('stage', SlaTarget::STAGE_TOTAL)
            ->when(!$scope->isSuperAdmin(), fn ($query) => $query->whereIn('booking_type_id', $scope->managedBookingTypeIds() ?: [-1]))
            ->orderBy('booking_type_id')
            ->get(['booking_type_id', 'target_hours', 'warn_ratio', 'set_by'])
            ->map(fn (SlaTarget $target): array => [
                'booking_type_id' => (int) $target->booking_type_id,
                'target_hours' => $target->target_hours,
                'warn_ratio' => $target->warn_ratio,
                'set_by' => $target->set_by,
            ])
            ->all();
    }

    /**
     * Sets the target of a booking type, or removes it with $hours = null.
     * @return array{saved: bool, booking_type_id: int, target_hours: ?float, warn_ratio: ?float}
     */
    public function set(AccessScope $scope, int $bookingTypeId, ?float $hours, float $warnRatio = 0.8): array
    {
        if (!$scope->can('targets.manage') || (!$scope->isSuperAdmin() && !in_array($bookingTypeId, $scope->adminOfBookingTypeIds(), true))) {
            $this->audit->denied('config.sla_target.set', 'sla_target', $bookingTypeId, 'no_permission', $scope->user, ['booking_type_id' => $bookingTypeId]);

            throw new DomainError('הגדרת יעד טיפול שמורה למנהל מערכת ולמפקד המחנה של התחום', 403);
        }

        if ($hours !== null && ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS)) {
            throw new DomainError('יעד הטיפול חייב להיות בין שעה אחת ל-180 יום', 422);
        }

        if ($warnRatio <= 0 || $warnRatio >= 1) {
            throw new DomainError('סף ההתראה המוקדמת חייב להיות בין 0 ל-1', 422);
        }

        return DB::transaction(function () use ($scope, $bookingTypeId, $hours, $warnRatio): array {
            $target = SlaTarget::query()->where('booking_type_id', $bookingTypeId)->where('stage', SlaTarget::STAGE_TOTAL)->first();
            $before = $target?->only(['target_hours', 'warn_ratio']);

            if ($hours === null) {
                $target?->delete();
            } else {
                $target ??= new SlaTarget(['booking_type_id' => $bookingTypeId, 'stage' => SlaTarget::STAGE_TOTAL]);
                $target->fill(['target_hours' => $hours, 'warn_ratio' => $warnRatio, 'set_by' => $scope->user->actorId()])->save();
            }

            $this->audit->record([
                'action' => $hours === null ? 'config.sla_target.remove' : 'config.sla_target.set',
                'entity_type' => 'sla_target',
                'entity_id' => $bookingTypeId,
                'booking_type_id' => $bookingTypeId,
                'before' => $before,
                'after' => $hours === null ? null : ['target_hours' => $hours, 'warn_ratio' => $warnRatio],
            ], $scope->user);

            return ['saved' => true, 'booking_type_id' => $bookingTypeId, 'target_hours' => $hours, 'warn_ratio' => $hours === null ? null : $warnRatio];
        });
    }
}

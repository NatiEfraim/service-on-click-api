<?php

namespace App\Support;

use App\Domain\Workflow\Workflow;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the current user may see and do, derived from the same "{role}_{booking type id}" permissions (and the unit) the rest of the
 * system uses, plus a few capabilities that separate managing the system from handling bookings:
 *   audit.view, sync.view, sync.operate, sla.manage, config.global - super admin
 *   users.manage, reports.view, targets.manage - super admin, or an admin (camp commander) of any booking type
 *   dashboard.view - super admin, admin, or approver (domain commander)
 * A handler (a control officer or vice approver) sees and acts on the bookings at their own stage, nothing else - on each booking type where
 * that is all they are: the same person may be a vice approver of one type and the approver of another.
 */
final class AccessScope
{
    /** @var array<int, string> the user's permission names, from the user and from the user's roles */
    public readonly array $permissions;

    /** @var ?array<int, array<int, int>> */
    private ?array $actionableStatuses = null;

    public function __construct(public readonly User $user, private readonly ?Workflow $workflow = null)
    {
        $this->permissions = $user->getAllPermissions()->pluck('name')->all();
    }

    public function workflow(): Workflow
    {
        return $this->workflow ?? app(Workflow::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->user->hasRole(RoleEnum::SUPER_ADMIN->toSpatieRoleName());
    }

    public function isAdmin(): bool
    {
        return $this->adminOfBookingTypeIds() !== [];
    }

    /** Booking types where the user holds any managing permission (admin, approver, vice approver, control officer). @return array<int, int> */
    public function managedBookingTypeIds(): array
    {
        return $this->bookingTypeIdsOf(['admin_', 'approver_', 'vice_approver_', 'transportation_control_officer_']);
    }

    /** @return array<int, int> */
    public function adminOfBookingTypeIds(): array
    {
        return $this->bookingTypeIdsOf(['admin_']);
    }

    /** @return array<int, int> */
    public function approverOfBookingTypeIds(): array
    {
        return $this->bookingTypeIdsOf(['approver_']);
    }

    /** A control officer or vice approver on every booking type they manage (the screen then shows the queue only, no dashboards or settings). */
    public function isHandlerOnly(): bool
    {
        return !$this->isSuperAdmin()
            && !$this->isAdmin()
            && $this->approverOfBookingTypeIds() === []
            && $this->managedBookingTypeIds() !== [];
    }

    /** A control officer or vice approver of this booking type, and neither its admin nor its approver: sees and acts on the bookings at the stage they handle. */
    public function isHandlerOnlyFor(int $bookingTypeId): bool
    {
        return !$this->isSuperAdmin()
            && in_array($bookingTypeId, $this->managedBookingTypeIds(), true)
            && !in_array($bookingTypeId, $this->adminOfBookingTypeIds(), true)
            && !in_array($bookingTypeId, $this->approverOfBookingTypeIds(), true);
    }

    /** Booking types whose dashboard the user may open: the super admin all, the camp commander his, the domain commander his own. @return array<int, int> */
    public function dashboardBookingTypeIds(): array
    {
        if ($this->isSuperAdmin()) {
            return $this->allManageableBookingTypeIds();
        }

        return $this->isAdmin() ? $this->adminOfBookingTypeIds() : $this->approverOfBookingTypeIds();
    }

    /** Every booking type that has bookings (the link-only "outsource" types have none). @return array<int, int> */
    public function allManageableBookingTypeIds(): array
    {
        return BookingType::query()->where('is_outsource', false)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** The statuses where this user has an action, per managed booking type - the handler's "own stage". @return array<int, array<int, int>> */
    public function actionableStatusesByType(): array
    {
        if ($this->actionableStatuses !== null) {
            return $this->actionableStatuses;
        }

        $byType = [];

        foreach ($this->managedBookingTypeIds() as $bookingTypeId) {
            foreach (StatusBookingEnum::cases() as $status) {
                if ($this->workflow()->availableActions($status, $this->permissions, $bookingTypeId) !== []) {
                    $byType[$bookingTypeId][] = $status->value;
                }
            }
        }

        return $this->actionableStatuses = $byType;
    }

    public function can(string $capability): bool
    {
        return match ($capability) {
            'audit.view', 'sync.view', 'sync.operate', 'sla.manage', 'config.global' => $this->isSuperAdmin(),
            'users.manage', 'reports.view', 'targets.manage' => $this->isSuperAdmin() || $this->isAdmin(),
            'dashboard.view' => $this->isSuperAdmin() || $this->isAdmin() || $this->approverOfBookingTypeIds() !== [],
            default => false,
        };
    }

    /**
     * The bookings the user may see: the booking types they manage, in their own unit; on a type where they are only a handler, just the
     * bookings at the stage they handle. The super admin sees every unit and type.
     */
    public function applyToBookings(Builder $query): Builder
    {
        if ($this->isSuperAdmin()) {
            return $query;
        }

        if ($this->user->unit_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $managed = $this->managedBookingTypeIds();
        $handlerTypes = array_values(array_filter($managed, fn (int $bookingTypeId): bool => $this->isHandlerOnlyFor($bookingTypeId)));

        $query->where('unit_id', $this->user->unit_id)->whereIn('booking_type_id', $managed ?: [-1]);

        if ($handlerTypes !== []) {
            $byType = $this->actionableStatusesByType();
            $leadTypes = array_values(array_diff($managed, $handlerTypes));

            $query->where(function (Builder $where) use ($handlerTypes, $leadTypes, $byType): void {
                $where->whereRaw('1 = 0');

                if ($leadTypes !== []) {
                    $where->orWhereIn('booking_type_id', $leadTypes);
                }

                foreach ($handlerTypes as $bookingTypeId) {
                    $where->orWhere(fn (Builder $type) => $type->where('booking_type_id', $bookingTypeId)->whereIn('status_id', $byType[$bookingTypeId] ?? [-1]));
                }
            });
        }

        return $query;
    }

    /** Is the booking in the user's unit and of a booking type they manage - at whatever stage it is in. (The super admin: every booking.) */
    public function managesBooking(?int $unitId, int $bookingTypeId): bool
    {
        return $this->isSuperAdmin()
            || ($unitId !== null && $unitId === $this->user->unit_id && in_array($bookingTypeId, $this->managedBookingTypeIds(), true));
    }

    /** May the user see the booking now: it is theirs to manage, and - for a handler - it is at a stage they act on. */
    public function canSeeBooking(?int $unitId, int $bookingTypeId, ?int $statusId = null): bool
    {
        if (!$this->managesBooking($unitId, $bookingTypeId)) {
            return false;
        }

        return !$this->isHandlerOnlyFor($bookingTypeId) || in_array($statusId, $this->actionableStatusesByType()[$bookingTypeId] ?? [], true);
    }

    /** Does the user work with this booking type at all: the super admin with every one, anyone else with the types they hold a managing permission on. */
    public function managesBookingType(int $bookingTypeId): bool
    {
        return $this->isSuperAdmin() || in_array($bookingTypeId, $this->managedBookingTypeIds(), true);
    }

    /** May edit the dynamic settings of (unit, booking type): the super admin anywhere; whoever manages the type, in their own unit (not a handler of it). */
    public function canManageSettings(int $unitId, int $bookingTypeId): bool
    {
        return $this->isSuperAdmin()
            || (!$this->isHandlerOnlyFor($bookingTypeId) && $this->managesBooking($unitId, $bookingTypeId));
    }

    /** @param array<int, string> $prefixes permission prefixes such as "admin_" @return array<int, int> */
    private function bookingTypeIdsOf(array $prefixes): array
    {
        $ids = [];

        foreach ($this->permissions as $permission) {
            foreach ($prefixes as $prefix) {
                $rest = substr($permission, strlen($prefix));

                // "approver_" must not claim "vice_approver_3": the prefix has to be the whole name before the id.
                if (str_starts_with($permission, $prefix) && $rest !== '' && ctype_digit($rest)) {
                    $ids[] = (int) $rest;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}

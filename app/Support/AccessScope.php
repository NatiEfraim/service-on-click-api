<?php

namespace App\Support;

use App\Domain\Workflow\Workflow;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the current user may see and do, derived from their "{role}_{booking type id}" permissions and unit.
 * Capabilities: audit.view, sync.view, sync.operate, sla.manage, config.global (super admin);
 * users.manage, reports.view, targets.manage (super admin or any admin); dashboard.view (also approvers).
 * A handler (control officer or vice approver) only sees bookings at their own stage, per booking type.
 * What the user may do to a booking is decided by the Workflow from $actingPermissions: the admin of a type (and the super admin, on every
 * type) acts at every stage.
 */
final class AccessScope
{
    /** @var array<int, string> The user's own permissions. */
    public readonly array $permissions;

    /** @var array<int, string> What the Workflow sees: the same, plus "admin_N" for every type when the user is the super admin. */
    public readonly array $actingPermissions;

    /** @var ?array<int, array<int, int>> */
    private ?array $actionableStatuses = null;

    public function __construct(public readonly User $user, private readonly ?Workflow $workflow = null)
    {
        $this->permissions = $user->getAllPermissions()->pluck('name')->all();
        $this->actingPermissions = $user->workflowPermissionNames();
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

    /** @return array<int, int> Booking types where the user holds any managing permission. */
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

    /** Control officer or vice approver on every type they manage (queue only, no dashboards or settings). */
    public function isHandlerOnly(): bool
    {
        return !$this->isSuperAdmin()
            && !$this->isAdmin()
            && $this->approverOfBookingTypeIds() === []
            && $this->managedBookingTypeIds() !== [];
    }

    /** Handler of this type, and neither its admin nor its approver. */
    public function isHandlerOnlyFor(int $bookingTypeId): bool
    {
        return !$this->isSuperAdmin()
            && in_array($bookingTypeId, $this->managedBookingTypeIds(), true)
            && !in_array($bookingTypeId, $this->adminOfBookingTypeIds(), true)
            && !in_array($bookingTypeId, $this->approverOfBookingTypeIds(), true);
    }

    /** @return array<int, int> Booking types whose dashboard the user may open. */
    public function dashboardBookingTypeIds(): array
    {
        if ($this->isSuperAdmin()) {
            return $this->allManageableBookingTypeIds();
        }

        return $this->isAdmin() ? $this->adminOfBookingTypeIds() : $this->approverOfBookingTypeIds();
    }

    /** @return array<int, int> Every booking type that has bookings (outsource types have none). */
    public function allManageableBookingTypeIds(): array
    {
        return BookingType::query()->where('is_outsource', false)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array<int, array<int, int>> Statuses where the user has an action, per managed type; they are positions (see Booking::positionStatus()). */
    public function actionableStatusesByType(): array
    {
        if ($this->actionableStatuses !== null) {
            return $this->actionableStatuses;
        }

        $byType = [];

        // The super admin holds no per-type permission but acts on every type.
        $bookingTypeIds = $this->isSuperAdmin() ? $this->allManageableBookingTypeIds() : $this->managedBookingTypeIds();

        foreach ($bookingTypeIds as $bookingTypeId) {
            foreach (StatusBookingEnum::cases() as $status) {
                if ($this->workflow()->availableActions($status, $this->actingPermissions, $bookingTypeId) !== []) {
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

    /** Limits a bookings query to the user's unit and managed types; handlers see only their own stage. */
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

                // By position, not status: a booking on hold stays with the handler whose stage it is parked at.
                foreach ($handlerTypes as $bookingTypeId) {
                    $where->orWhere(fn (Builder $type) => $type->where('booking_type_id', $bookingTypeId)->atPosition($byType[$bookingTypeId] ?? [-1]));
                }
            });
        }

        return $query;
    }

    /** In the user's unit and of a managed type, at any stage. Super admin: always. */
    public function managesBooking(?int $unitId, int $bookingTypeId): bool
    {
        return $this->isSuperAdmin()
            || ($unitId !== null && $unitId === $this->user->unit_id && in_array($bookingTypeId, $this->managedBookingTypeIds(), true));
    }

    /** Managed by the user and, for a handler, at a stage they act on. $statusId is the booking's position (Booking::positionStatus()), not its raw status. */
    public function canSeeBooking(?int $unitId, int $bookingTypeId, ?int $statusId = null): bool
    {
        if (!$this->managesBooking($unitId, $bookingTypeId)) {
            return false;
        }

        return !$this->isHandlerOnlyFor($bookingTypeId) || in_array($statusId, $this->actionableStatusesByType()[$bookingTypeId] ?? [], true);
    }

    /** Super admin, or holds a managing permission on the type. */
    public function managesBookingType(int $bookingTypeId): bool
    {
        return $this->isSuperAdmin() || in_array($bookingTypeId, $this->managedBookingTypeIds(), true);
    }

    /** Super admin anywhere; otherwise a non-handler who manages the type in their own unit. */
    public function canManageSettings(int $unitId, int $bookingTypeId): bool
    {
        return $this->isSuperAdmin()
            || (!$this->isHandlerOnlyFor($bookingTypeId) && $this->managesBooking($unitId, $bookingTypeId));
    }

    /**
     * @param array<int, string> $prefixes permission prefixes such as "admin_"
     * @return array<int, int>
     */
    private function bookingTypeIdsOf(array $prefixes): array
    {
        $ids = [];

        foreach ($this->permissions as $permission) {
            foreach ($prefixes as $prefix) {
                $rest = substr($permission, strlen($prefix));

                // "approver_" must not match "vice_approver_3".
                if (str_starts_with($permission, $prefix) && $rest !== '' && ctype_digit($rest)) {
                    $ids[] = (int) $rest;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}

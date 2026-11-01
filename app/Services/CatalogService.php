<?php

namespace App\Services;

use App\Enums\BookingStageEnum;
use App\Enums\RoleEnum;
use App\Models\ActionType;
use App\Models\BookingType;
use App\Models\BookingTypeHasStatus;
use App\Models\Department;
use App\Models\GroupBookingType;
use App\Models\Status;
use App\Models\Unit;
use App\Support\AccessScope;

/** What a screen needs when it opens: who the user is and what they may do (me), and the lists every dropdown is made of (catalog). */
class CatalogService
{
    private const CAPABILITIES = [
        'audit.view', 'sync.view', 'sync.operate', 'sla.manage', 'config.global',
        'users.manage', 'dashboard.view', 'reports.view', 'targets.manage',
    ];

    /** @return array<string, mixed> */
    public function me(AccessScope $scope): array
    {
        $user = $scope->user->loadMissing(['unit:id,name,internal_name', 'department:id,name']);

        return [
            'user' => [
                'uuid' => $user->uuid,
                'personal_number' => $user->personal_number,
                'personal_id' => $user->personal_id,
                'full_name' => $user->full_name,
                'unit_id' => $user->unit_id,
                'unit_name' => $user->unit?->displayName(),
                'department_id' => $user->department_id,
                'department_name' => $user->department?->name,
            ],
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $scope->permissions,
            'capabilities' => collect(self::CAPABILITIES)->mapWithKeys(fn (string $capability): array => [$capability => $scope->can($capability)])->all(),
            'is_super_admin' => $scope->isSuperAdmin(),
            'is_admin' => $scope->isAdmin(),
            'is_handler_only' => $scope->isHandlerOnly(),
            'managed_booking_types' => $scope->isSuperAdmin() ? $scope->allManageableBookingTypeIds() : $scope->managedBookingTypeIds(),
            'dashboard_booking_types' => $scope->dashboardBookingTypeIds(),
        ];
    }

    /** @return array<string, mixed> */
    public function catalog(AccessScope $scope): array
    {
        $unitsQuery = Unit::query()->where('is_deleted', false)->orderBy('id');
        $departmentsQuery = Department::query()->where('is_active', true)->orderBy('name');

        // Only the super admin knows every unit; everyone else knows their own.
        if (!$scope->isSuperAdmin()) {
            $unitsQuery->where('id', $scope->user->unit_id);
            $departmentsQuery->where('unit_id', $scope->user->unit_id);
        }

        return [
            'statuses' => Status::query()->orderBy('position')->get(['id', 'name', 'display_name', 'position'])->all(),
            'stages' => array_map(fn (BookingStageEnum $stage): array => ['id' => $stage->value, 'label' => $stage->label()], BookingStageEnum::cases()),
            'roles' => array_map(fn (RoleEnum $role): array => ['id' => $role->value, 'name' => $role->toSpatieRoleName(), 'label' => $role->getPermissionDisplayName()], RoleEnum::cases()),
            'booking_types' => BookingType::query()->orderBy('id')->get(['id', 'name', 'display_name', 'icon', 'is_outsource', 'position', 'has_group'])->all(),
            'booking_type_statuses' => BookingTypeHasStatus::query()->orderBy('status_id')->get(['booking_type_id', 'status_id'])
                ->groupBy('booking_type_id')->map(fn ($rows) => $rows->pluck('status_id')->map(fn ($id): int => (int) $id)->values()->all())->all(),
            'groups' => GroupBookingType::query()->orderBy('position')->get(['id', 'name', 'display_name'])->all(),
            'units' => $unitsQuery->get()->map(fn (Unit $unit): array => ['id' => $unit->id, 'code' => $unit->name, 'name' => $unit->displayName()])->all(),
            'departments' => $departmentsQuery->get(['id', 'unit_id', 'name'])->all(),
            'action_types' => ActionType::query()->orderBy('id')->get(['id', 'type', 'display_type'])->all(),
        ];
    }
}

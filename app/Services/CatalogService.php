<?php

namespace App\Services;

use App\Domain\Workflow\TypeRoute;
use App\Enums\BookingStageEnum;
use App\Enums\FormSectionEnum;
use App\Enums\RoleEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasStatus;
use App\Models\Department;
use App\Models\Status;
use App\Models\Unit;
use App\Support\AccessScope;

/** Screen bootstrap data: who the user is and what they may do (me), and the dropdown lists (catalog). */
class CatalogService
{
    private const CAPABILITIES = [
        'audit.view', 'sync.view', 'sync.operate', 'sla.manage', 'config.global',
        'users.manage', 'dashboard.view', 'reports.view', 'targets.manage',
    ];

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
            // The ICT service desk commander (admin_ict_N): the desk's dashboard, users and reports - on the ICT types only.
            'is_ict_admin' => $scope->isIctAdmin(),
            'is_handler_only' => $scope->isHandlerOnly(),
            'managed_booking_types' => $scope->isSuperAdmin() ? $scope->allManageableBookingTypeIds() : $scope->managedBookingTypeIds(),
            'dashboard_booking_types' => $scope->dashboardBookingTypeIds(),
            // The types with a route of their own the user acts on, and the ones they own (dashboard, settings).
            'route_booking_types' => $scope->isSuperAdmin() ? $scope->workflow()->routeTypeIds() : $scope->routeBookingTypeIds(),
            'route_owner_booking_types' => $scope->isSuperAdmin() ? $scope->workflow()->routeTypeIds() : $scope->routeOwnerBookingTypeIds(),
        ];
    }

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
            'booking_types' => BookingType::query()->orderBy('id')->get(['id', 'name', 'display_name', 'icon', 'position'])->all(),
            'booking_type_statuses' => BookingTypeHasStatus::query()->orderBy('status_id')->get(['booking_type_id', 'status_id'])
                ->groupBy('booking_type_id')->map(fn ($rows) => $rows->pluck('status_id')->map(fn ($id): int => (int) $id)->values()->all())->all(),
            'units' => $unitsQuery->get()->map(fn (Unit $unit): array => ['id' => $unit->id, 'code' => $unit->name, 'name' => $unit->displayName()])->all(),
            'departments' => $departmentsQuery->get(['id', 'unit_id', 'name'])->all(),
            // The sections a form may have, and the booking types with a route of their own (steps of the progress bar, roles).
            'sections' => FormSectionEnum::rows(),
            'routes' => $this->routes($scope),
        ];
    }

    /** @return array<int, array<string, mixed>> booking type id => the route as the screens need it */
    private function routes(AccessScope $scope): array
    {
        $workflow = $scope->workflow();
        $routes = [];

        if ($workflow->routeTypeIds() === []) {
            return $routes;
        }

        $labels = Status::query()->pluck('display_name', 'id');
        $roleRow = fn (RoleEnum $role): array => ['id' => $role->value, 'name' => $role->toSpatieRoleName(), 'label' => $role->getPermissionDisplayName()];

        foreach ($workflow->routeTypeIds() as $bookingTypeId) {
            /** @var TypeRoute $route */
            $route = $workflow->routeFor($bookingTypeId);

            $routes[$bookingTypeId] = [
                'key' => $route->key(),
                'initial_status_id' => $route->initialStatus(),
                'success_status_id' => $route->successStatus(),
                'status_ids' => $route->statusIds(),
                'steps' => array_map(fn (int $statusId): array => ['status_id' => $statusId, 'label' => $labels[$statusId] ?? (string) $statusId], $route->steps()),
                'sections' => FormSectionEnum::rows(),
                'requester_role' => $roleRow($route->requesterRole()),
                'owner_role' => $roleRow($route->ownerRole()),
                'roles' => array_map($roleRow, $route->roles()),
            ];
        }

        return $routes;
    }
}

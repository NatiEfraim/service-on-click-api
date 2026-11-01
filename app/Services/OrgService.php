<?php

namespace App\Services;

use App\Domain\Org\OrgRules;
use App\Exceptions\DomainError;
use App\Models\Department;
use App\Models\Unit;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin management of units (the letter the mobile app knows plus the name shown here) and departments.
 * A unit's letter cannot change: the mobile app and UnitEnum find the unit by it.
 */
class OrgService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ConfigOutboxService $configOutbox,
    ) {
    }

    /** Every unit with its departments and head counts. @return array<int, array<string, mixed>> */
    public function listing(AccessScope $scope): array
    {
        $this->need($scope, 'org.view');

        $usersByUnit = User::query()->where('is_deleted', false)->selectRaw('unit_id, COUNT(*) AS total')->groupBy('unit_id')->pluck('total', 'unit_id');
        $usersByDepartment = User::query()->where('is_deleted', false)->whereNotNull('department_id')->selectRaw('department_id, COUNT(*) AS total')->groupBy('department_id')->pluck('total', 'department_id');
        $departments = Department::query()->orderBy('name')->get()->groupBy('unit_id');

        return Unit::query()->where('is_deleted', false)->orderBy('id')->get()->map(fn (Unit $unit): array => [
            'id' => $unit->id,
            'code' => $unit->name,
            'name' => $unit->displayName(),
            'users' => (int) ($usersByUnit[$unit->id] ?? 0),
            'departments' => ($departments[$unit->id] ?? collect())->map(fn (Department $department): array => [
                'id' => $department->id,
                'unit_id' => $department->unit_id,
                'name' => $department->name,
                'is_active' => $department->is_active,
                'users' => (int) ($usersByDepartment[$department->id] ?? 0),
            ])->values()->all(),
        ])->all();
    }

    /**
     * Adds a unit (code and name) or renames one (the code stays).
     * @param array{code?: string, name?: string} $data
     * @return array{id: int, code: string, name: string}
     */
    public function saveUnit(AccessScope $scope, ?int $id, array $data): array
    {
        $this->need($scope, $id ? 'org.unit.update' : 'org.unit.create');

        $others = Unit::query()
            ->when($id, fn ($query) => $query->where('id', '!=', $id))
            ->get()
            ->map(fn (Unit $unit): array => ['code' => $unit->name, 'name' => $unit->displayName()])
            ->all();

        if ($error = OrgRules::validateUnit($data, $others)) {
            throw new DomainError($error, 422);
        }

        $code = strtoupper(trim($data['code']));
        $name = trim($data['name']);

        return DB::transaction(function () use ($scope, $id, $code, $name): array {
            $unit = $id ? Unit::query()->where('is_deleted', false)->findOrFail($id) : new Unit(['name' => $code]);

            if ($unit->exists && $unit->name !== $code) {
                throw new DomainError('האות של יחידה קיימת אינה ניתנת לשינוי', 422);
            }

            $before = $unit->exists ? ['code' => $unit->name, 'name' => $unit->displayName()] : null;

            $unit->internal_name = $name;
            $unit->save();

            $this->audit->record([
                'action' => $id ? 'org.unit.update' : 'org.unit.create',
                'entity_type' => 'unit',
                'entity_id' => $unit->id,
                'unit_id' => $unit->id,
                'before' => $before,
                'after' => ['code' => $unit->name, 'name' => $unit->displayName()],
            ], $scope->user);

            // The mobile app learns of a unit by its letter only; names and departments never leave this system.
            if (!$id) {
                $this->configOutbox->queue('unit', 'create', ['unit_id' => $unit->id, 'data' => ['unit_id' => $unit->id, 'code' => $unit->name]]);
            }

            return ['id' => $unit->id, 'code' => $unit->name, 'name' => $unit->displayName()];
        });
    }

    /**
     * Adds a department to a unit, or renames / toggles one.
     * @param array{name?: string, is_active?: bool} $data
     * @return array{id: int, unit_id: int, name: string, is_active: bool}
     * @throws DomainError 409 when people are still in it
     */
    public function saveDepartment(AccessScope $scope, ?int $id, ?int $unitId, array $data): array
    {
        $this->need($scope, $id ? 'org.department.update' : 'org.department.create');

        $department = $id
            ? Department::query()->findOrFail($id)
            : new Department(['unit_id' => Unit::query()->where('is_deleted', false)->findOrFail($unitId)->id, 'is_active' => true]);

        $siblings = Department::query()
            ->where('unit_id', $department->unit_id)
            ->when($id, fn ($query) => $query->where('id', '!=', $id))
            ->get(['name'])
            ->toArray();

        if ((!$id || array_key_exists('name', $data)) && ($error = OrgRules::validateDepartment($data, $siblings))) {
            throw new DomainError($error, 422);
        }

        if ($id && array_key_exists('is_active', $data) && !$data['is_active'] && $department->is_active
            && ($error = OrgRules::canDeactivateDepartment(User::query()->where('department_id', $department->id)->where('is_deleted', false)->count()))) {
            throw new DomainError($error, 409);
        }

        return DB::transaction(function () use ($scope, $id, $department, $data): array {
            $before = $department->exists ? $department->only(['name', 'is_active']) : null;

            if (array_key_exists('name', $data)) {
                $department->name = trim($data['name']);
            }

            if (array_key_exists('is_active', $data)) {
                $department->is_active = (bool) $data['is_active'];
            }

            $department->save();

            $this->audit->record([
                'action' => $id ? 'org.department.update' : 'org.department.create',
                'entity_type' => 'department',
                'entity_id' => $department->id,
                'unit_id' => $department->unit_id,
                'before' => $before,
                'after' => $department->only(['name', 'is_active']),
            ], $scope->user);

            return ['id' => $department->id, 'unit_id' => (int) $department->unit_id, 'name' => $department->name, 'is_active' => $department->is_active];
        });
    }

    private function need(AccessScope $scope, string $action): void
    {
        if (!$scope->can('config.global')) {
            $this->audit->denied($action, 'org', null, 'no_permission', $scope->user);

            throw new DomainError('ניהול יחידות ומחלקות שמור למנהל מערכת', 403);
        }
    }
}

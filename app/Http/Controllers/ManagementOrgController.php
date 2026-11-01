<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDepartmentRequest;
use App\Http\Requests\SaveUnitRequest;
use App\Http\Requests\SetUserDepartmentRequest;
use App\Services\OrgService;
use App\Services\UserAdminService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ManagementOrgController extends Controller
{
    // API group: Org - units and departments (super admin), and which department a person is in (admin, super admin).

    public function __construct(
        private readonly OrgService $orgService,
        private readonly UserAdminService $userAdminService,
    ) {
    }

    /** GET /api/bookings-super-admin-management/org - every unit with its departments and head counts. @return JsonResponse */
    public function listing(): JsonResponse
    {
        return response()->json(['data' => $this->orgService->listing($this->accessScope())], Response::HTTP_OK);
    }

    /** POST /api/bookings-super-admin-management/org/units - add a unit (code = its letter, name = what people see). @param SaveUnitRequest $request @return JsonResponse */
    public function createUnit(SaveUnitRequest $request): JsonResponse
    {
        $unit = $this->orgService->saveUnit($this->accessScope(), null, $request->only(['code', 'name']));

        return response()->json(['data' => $unit, 'message' => 'היחידה נוספה'], Response::HTTP_CREATED);
    }

    /** PUT /api/bookings-super-admin-management/org/units/{id} - rename a unit (its code stays). @param int $id, SaveUnitRequest $request @return JsonResponse */
    public function updateUnit(int $id, SaveUnitRequest $request): JsonResponse
    {
        $unit = $this->orgService->saveUnit($this->accessScope(), $id, $request->only(['code', 'name']));

        return response()->json(['data' => $unit, 'message' => 'היחידה עודכנה'], Response::HTTP_OK);
    }

    /** POST /api/bookings-super-admin-management/org/units/{unitId}/departments - add a department. @param int $unitId, SaveDepartmentRequest $request @return JsonResponse */
    public function createDepartment(int $unitId, SaveDepartmentRequest $request): JsonResponse
    {
        $department = $this->orgService->saveDepartment($this->accessScope(), null, $unitId, $request->only(['name']));

        return response()->json(['data' => $department, 'message' => 'המחלקה נוספה'], Response::HTTP_CREATED);
    }

    /** PUT /api/bookings-super-admin-management/org/departments/{id} - rename a department or switch it on / off (is_active). @param int $id, SaveDepartmentRequest $request @return JsonResponse */
    public function updateDepartment(int $id, SaveDepartmentRequest $request): JsonResponse
    {
        $department = $this->orgService->saveDepartment($this->accessScope(), $id, null, $request->only(['name', 'is_active']));

        return response()->json(['data' => $department, 'message' => 'המחלקה עודכנה'], Response::HTTP_OK);
    }

    /** PUT /api/bookings-admin-management/users/{uuid}/department - put a person in a department of their unit (department_id null = none). @param string $uuid, SetUserDepartmentRequest $request @return JsonResponse */
    public function setUserDepartment(string $uuid, SetUserDepartmentRequest $request): JsonResponse
    {
        $result = $this->userAdminService->setDepartment(
            $this->accessScope(),
            $uuid,
            $request->input('department_id') === null ? null : (int) $request->input('department_id'),
        );

        return response()->json(['data' => $result, 'message' => 'שיוך המחלקה עודכן'], Response::HTTP_OK);
    }
}

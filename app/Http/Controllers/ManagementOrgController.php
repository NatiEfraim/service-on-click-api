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
    public function __construct(
        private readonly OrgService $orgService,
        private readonly UserAdminService $userAdminService,
    ) {
    }

    /** Every unit with its departments and head counts. */
    public function listing(): JsonResponse
    {
        return response()->json(['data' => $this->orgService->listing($this->accessScope())], Response::HTTP_OK);
    }

    /** Add a unit (code = its letter, name = what people see). */
    public function createUnit(SaveUnitRequest $request): JsonResponse
    {
        $unit = $this->orgService->saveUnit($this->accessScope(), null, $request->only(['code', 'name']));

        return response()->json(['data' => $unit, 'message' => 'היחידה נוספה'], Response::HTTP_CREATED);
    }

    /** Rename a unit (its code stays). */
    public function updateUnit(int $id, SaveUnitRequest $request): JsonResponse
    {
        $unit = $this->orgService->saveUnit($this->accessScope(), $id, $request->only(['code', 'name']));

        return response()->json(['data' => $unit, 'message' => 'היחידה עודכנה'], Response::HTTP_OK);
    }

    /** Add a department. */
    public function createDepartment(int $unitId, SaveDepartmentRequest $request): JsonResponse
    {
        $department = $this->orgService->saveDepartment($this->accessScope(), null, $unitId, $request->only(['name']));

        return response()->json(['data' => $department, 'message' => 'המחלקה נוספה'], Response::HTTP_CREATED);
    }

    /** Rename a department or switch it on / off (is_active). */
    public function updateDepartment(int $id, SaveDepartmentRequest $request): JsonResponse
    {
        $department = $this->orgService->saveDepartment($this->accessScope(), $id, null, $request->only(['name', 'is_active']));

        return response()->json(['data' => $department, 'message' => 'המחלקה עודכנה'], Response::HTTP_OK);
    }

    /** Put a person in a department of their unit (department_id null = none). */
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

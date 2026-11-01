<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderFormFieldsRequest;
use App\Http\Requests\RestrictionImpactRequest;
use App\Http\Requests\SetFieldEditableRequest;
use App\Http\Requests\SetSlaTargetRequest;
use App\Http\Requests\StoreFormFieldRequest;
use App\Http\Requests\UpdateFormFieldRequest;
use App\Services\FormFieldService;
use App\Services\SettingsService;
use App\Services\SlaTargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManagementSettingsController extends Controller
{
    // API group: settings of a booking type (read), the editable flag of a field and the handling targets - thin: the rules are in the services and in AccessScope.

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SlaTargetService $slaTargetService,
        private readonly FormFieldService $formFieldService,
    ) {
    }

    /** GET /api/bookings-approver-management/settings/{unitId}/{bookingTypeId} - system references, form fields and options, statement and managers of a booking type in a unit. @param int $unitId, int $bookingTypeId @return JsonResponse */
    public function settings(int $unitId, int $bookingTypeId): JsonResponse
    {
        return response()->json(['data' => $this->settingsService->view($this->accessScope(), $unitId, $bookingTypeId)], Response::HTTP_OK);
    }

    /** POST /api/bookings-approver-management/settings/references/{id}/impact - which bookings blocking these dates would cancel (nothing is saved). @param int $id, RestrictionImpactRequest $request @return JsonResponse */
    public function restrictionImpact(int $id, RestrictionImpactRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->settingsService->restrictionImpact($this->accessScope(), $id, $request->input('dates'))], Response::HTTP_OK);
    }

    /** PUT /api/bookings-super-admin-management/form-fields/{id}/editable - may an approver correct this date / time field's value. @param int $id, SetFieldEditableRequest $request @return JsonResponse */
    public function setFieldEditable(int $id, SetFieldEditableRequest $request): JsonResponse
    {
        $result = $this->formFieldService->setEditable($this->accessScope(), $id, $request->boolean('editable'), $request->integer('version'));

        return response()->json(['data' => $result, 'message' => 'השדה עודכן'], Response::HTTP_OK);
    }

    /** POST /api/bookings-super-admin-management/form-fields/{unitId}/{bookingTypeId} - add a field at the end of the form. @param int $unitId, int $bookingTypeId, StoreFormFieldRequest $request @return JsonResponse */
    public function createField(int $unitId, int $bookingTypeId, StoreFormFieldRequest $request): JsonResponse
    {
        $field = $this->formFieldService->create($this->accessScope(), $unitId, $bookingTypeId, $request->validated());

        return response()->json(['data' => $field, 'message' => 'השדה נוסף'], Response::HTTP_CREATED);
    }

    /** PUT /api/bookings-super-admin-management/form-fields/{id} - change a field's name, type, required or editable (send the version). @param int $id, UpdateFormFieldRequest $request @return JsonResponse */
    public function updateField(int $id, UpdateFormFieldRequest $request): JsonResponse
    {
        $field = $this->formFieldService->update($this->accessScope(), $id, $request->safe()->except('version'), $request->integer('version'));

        return response()->json(['data' => $field, 'message' => 'השדה עודכן'], Response::HTTP_OK);
    }

    /** PUT /api/bookings-super-admin-management/form-fields/{unitId}/{bookingTypeId}/order - the new order of the stand-alone fields of the form. @param int $unitId, int $bookingTypeId, ReorderFormFieldsRequest $request @return JsonResponse */
    public function reorderFields(int $unitId, int $bookingTypeId, ReorderFormFieldsRequest $request): JsonResponse
    {
        $result = $this->formFieldService->reorder($this->accessScope(), $unitId, $bookingTypeId, $request->input('ids'));

        return response()->json(['data' => $result, 'message' => 'סדר השדות נשמר'], Response::HTTP_OK);
    }

    /** DELETE /api/bookings-super-admin-management/form-fields/{id} - take a field out of the form: archived when bookings hold values for it, deleted when none does (?version= optional). @param int $id, Request $request @return JsonResponse */
    public function removeField(int $id, Request $request): JsonResponse
    {
        $version = $request->input('version');
        $result = $this->formFieldService->remove($this->accessScope(), $id, is_numeric($version) ? (int) $version : null);

        return response()->json([
            'data' => $result,
            'message' => $result['mode'] === 'archive' ? 'השדה הוסר מהטופס והערכים שבקריאות נשמרו' : 'השדה נמחק',
        ], Response::HTTP_OK);
    }

    /** GET /api/bookings-admin-management/targets - the handling targets of the booking types the user manages. @return JsonResponse */
    public function targets(): JsonResponse
    {
        return response()->json(['data' => $this->slaTargetService->list($this->accessScope())], Response::HTTP_OK);
    }

    /** PUT /api/bookings-admin-management/targets - set (or remove, with target_hours null) the handling target of a booking type. @param SetSlaTargetRequest $request @return JsonResponse */
    public function setTarget(SetSlaTargetRequest $request): JsonResponse
    {
        $result = $this->slaTargetService->set(
            scope: $this->accessScope(),
            bookingTypeId: $request->integer('booking_type_id'),
            hours: $request->input('target_hours') === null ? null : (float) $request->input('target_hours'),
            warnRatio: $request->filled('warn_ratio') ? (float) $request->input('warn_ratio') : 0.8,
        );

        return response()->json(['data' => $result, 'message' => 'יעד הטיפול נשמר'], Response::HTTP_OK);
    }
}

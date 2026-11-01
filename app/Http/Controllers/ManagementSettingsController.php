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
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SlaTargetService $slaTargetService,
        private readonly FormFieldService $formFieldService,
    ) {
    }

    /** System references, form fields and options, statement and managers of a booking type in a unit. */
    public function settings(int $unitId, int $bookingTypeId): JsonResponse
    {
        return response()->json(['data' => $this->settingsService->view($this->accessScope(), $unitId, $bookingTypeId)], Response::HTTP_OK);
    }

    /** Which bookings blocking these dates would cancel (nothing is saved). */
    public function restrictionImpact(int $id, RestrictionImpactRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->settingsService->restrictionImpact($this->accessScope(), $id, $request->input('dates'))], Response::HTTP_OK);
    }

    /** May an approver correct this date / time field's value. */
    public function setFieldEditable(int $id, SetFieldEditableRequest $request): JsonResponse
    {
        $result = $this->formFieldService->setEditable($this->accessScope(), $id, $request->boolean('editable'), $request->integer('version'));

        return response()->json(['data' => $result, 'message' => 'השדה עודכן'], Response::HTTP_OK);
    }

    /** Add a field at the end of the form. */
    public function createField(int $unitId, int $bookingTypeId, StoreFormFieldRequest $request): JsonResponse
    {
        $field = $this->formFieldService->create($this->accessScope(), $unitId, $bookingTypeId, $request->validated());

        return response()->json(['data' => $field, 'message' => 'השדה נוסף'], Response::HTTP_CREATED);
    }

    /** Change a field's name, type, required or editable (send the version). */
    public function updateField(int $id, UpdateFormFieldRequest $request): JsonResponse
    {
        $field = $this->formFieldService->update($this->accessScope(), $id, $request->safe()->except('version'), $request->integer('version'));

        return response()->json(['data' => $field, 'message' => 'השדה עודכן'], Response::HTTP_OK);
    }

    /** The new order of the stand-alone fields of the form. */
    public function reorderFields(int $unitId, int $bookingTypeId, ReorderFormFieldsRequest $request): JsonResponse
    {
        $result = $this->formFieldService->reorder($this->accessScope(), $unitId, $bookingTypeId, $request->input('ids'));

        return response()->json(['data' => $result, 'message' => 'סדר השדות נשמר'], Response::HTTP_OK);
    }

    /** Archives the field when bookings hold values for it, deletes it otherwise (?version= optional). */
    public function removeField(int $id, Request $request): JsonResponse
    {
        $version = $request->input('version');
        $result = $this->formFieldService->remove($this->accessScope(), $id, is_numeric($version) ? (int) $version : null);

        return response()->json([
            'data' => $result,
            'message' => $result['mode'] === 'archive' ? 'השדה הוסר מהטופס והערכים שבקריאות נשמרו' : 'השדה נמחק',
        ], Response::HTTP_OK);
    }

    /** The handling targets of the booking types the user manages. */
    public function targets(): JsonResponse
    {
        return response()->json(['data' => $this->slaTargetService->list($this->accessScope())], Response::HTTP_OK);
    }

    /** Set (or remove, with target_hours null) the handling target of a booking type. */
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

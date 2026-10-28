<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignAdminRoleRequest;
use App\Http\Requests\RemoveOptionFieldRequest;
use App\Http\Requests\UpdateFormFieldOptionRequest;
use App\Http\Requests\UpdateOptionFieldRequest;
use App\Http\Requests\UpdateStatementRequest;
use App\Services\BookingSuperAdminManagementService;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\JsonResponse;
use App\Enums\HttpStatusCodeEnum;
use App\Enums\StatementShowTimeEnum;
use Illuminate\Http\Request;



class BookingSuperAdminManagementController extends Controller
{

    // API group: Bookings Super Admin Management - system settings for the super_admin role only.

    public function __construct(
        private readonly BookingSuperAdminManagementService $bookingSuperAdminManagementService
    ) {
    }

    /** POST /api/bookings-super-admin-management/assign-admin-role - assign/remove admin roles and permissions per booking type. @param AssignAdminRoleRequest $request @return JsonResponse */
    public function assignAdminRole(AssignAdminRoleRequest $request): JsonResponse
    {

        $result = $this->bookingSuperAdminManagementService->assignAdminRole(validated: $request->validated());
        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::UNPROCESSABLE_ENTITY => response()->json(['message' => HttpStatusCodeEnum::UNPROCESSABLE_ENTITY->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }
        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** GET /api/bookings-super-admin-management/get-admins - list admins, optional ?identifier (personal id or name) filter. @param Request $request @return JsonResponse */
    public function getAdmins(Request $request): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->getAdmins(
            identifier: $request->query('identifier'),
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** GET /api/bookings-super-admin-management/get-admin-permissions/{userUuid} - booking types with the user's is_admin flag. @param string $userUuid @return JsonResponse */
    public function getAdminPermissions(string $userUuid): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->getAdminPermissions(
            userUuid: $userUuid
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** GET /api/bookings-super-admin-management/index-selects/{unitId}/{bookingType} - selectable fields/options of a booking type in a unit. @param int $unitId, string $bookingType @return JsonResponse */
    public function indexSelects(int $unitId, string $bookingType): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->indexSelects(
            unitId: $unitId,
            bookingType: $bookingType,
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => 'אין תוצאות'], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** GET /api/bookings-super-admin-management/get-select-options/{formFieldId} - fetch a form field with its select options. @param int $formFieldId @return JsonResponse */
    public function getSelectOptions(int $formFieldId): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->getSelectOptions(
            formFieldId: $formFieldId
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => 'אין תוצאות'], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }


    /** GET /api/bookings-super-admin-management/get-options - option fields of ?formFieldId, optionally under parent ?optionFieldId. @param Request $request @return JsonResponse */
    public function getOptions(Request $request): JsonResponse
    {
      
        $result = $this->bookingSuperAdminManagementService->getOptions(
            formFieldId: $request->query('formFieldId'),
            optionFieldId: $request->query('optionFieldId'),
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => 'אין תוצאות'], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }


    /** GET /api/bookings-super-admin-management/get-statement/{bookingTypeId} - fetch the statement of a booking type. @param int $bookingTypeId @return JsonResponse */
    public function getStatement(int $bookingTypeId): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->getStatement(
            bookingTypeId: $bookingTypeId
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => 'אין תוצאות'], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data'=> $result], Response::HTTP_OK);
    }


    /** GET /api/bookings-super-admin-management/get-siblings-options/{optionFieldId} - option fields that share the given option's parent. @param int $optionFieldId @return JsonResponse */
    public function getSiblingsOptions(int $optionFieldId): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->getSiblingsOptions(
            optionFieldId: $optionFieldId
        );

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => 'אין תוצאות'], Response::HTTP_NOT_FOUND),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }

    /** PUT /api/bookings-super-admin-management/update-select-option/{formFieldId} - update option names and categories of a field. @param int $formFieldId, UpdateFormFieldOptionRequest $request @return JsonResponse */
    public function updateSelectOptions(int $formFieldId, UpdateFormFieldOptionRequest $request): JsonResponse
    {

        $result = $this->bookingSuperAdminManagementService->updateSelectOptions(formFieldId: $formFieldId, fieldOptions: $request->input('option_fields'));

        return match ($result) {
            HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
            HttpStatusCodeEnum::OK => response()->json(['message' => 'שינויים עודכנו בהצלחה'], Response::HTTP_OK),
            HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_NOT_FOUND),
            HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
            default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
        };
    }

    /** PUT /api/bookings-super-admin-management/update-options/{formFieldId} - sync the nested option_fields tree of a form field. @param int $formFieldId, UpdateOptionFieldRequest $request @return JsonResponse */
    public function updateOptions(int $formFieldId, UpdateOptionFieldRequest $request): JsonResponse
    {
   
        $result = $this->bookingSuperAdminManagementService->updateOptions(formFieldId: $formFieldId, fieldOptions: $request->input('option_fields'));

        return match ($result) {
            HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
            HttpStatusCodeEnum::OK => response()->json(['message' => 'שינויים עודכנו בהצלחה'], Response::HTTP_OK),
            HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_NOT_FOUND),
            HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
            default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
        };
    }

    /** PUT /api/bookings-super-admin-management/update-option/{formFieldId} - update an existing option or create a new one. @param int $formFieldId, UpdateOptionFieldRequest $request @return JsonResponse */
    public function updateOption(int $formFieldId, UpdateOptionFieldRequest $request): JsonResponse
    {
  
        $result = $this->bookingSuperAdminManagementService->updateOption(formFieldId: $formFieldId, fieldOptions: $request->validated());

        if ($result instanceof HttpStatusCodeEnum) {
            return match ($result) {
                HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
                HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
                HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => 'אין תוצאות'], Response::HTTP_NOT_FOUND),
                HttpStatusCodeEnum::CONFLICT => response()->json(['message' => HttpStatusCodeEnum::CONFLICT->getMessage()], Response::HTTP_CONFLICT),
                default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
            };
        }

        return response()->json(['data' => $result], Response::HTTP_OK);
    }


    /** DELETE /api/bookings-super-admin-management/remove-options/{formFieldId} - delete root options and all their children. @param int $formFieldId, RemoveOptionFieldRequest $request @return JsonResponse */
    public function removeSelectOptions(int $formFieldId, RemoveOptionFieldRequest $request): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->removeSelectOptions(formFieldId: $formFieldId, fieldOptions: $request->input('option_fields'));
        return match ($result) {
            HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
            HttpStatusCodeEnum::OK => response()->json(['message' => 'שינויים עודכנו בהצלחה'], Response::HTTP_OK),
            HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_NOT_FOUND),
            HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
            default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
        };
    }

    /** DELETE /api/bookings-super-admin-management/remove-option/{formFieldId} - delete a single option by option_id. @param int $formFieldId, RemoveOptionFieldRequest $request @return JsonResponse */
    public function removeOption(int $formFieldId, RemoveOptionFieldRequest $request): JsonResponse
    {
        $result = $this->bookingSuperAdminManagementService->removeOption(formFieldId: $formFieldId, fieldOptions: $request->validated());
        return match ($result) {
            HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
            HttpStatusCodeEnum::OK => response()->json(['message' => 'שינויים עודכנו בהצלחה'], Response::HTTP_OK),
            HttpStatusCodeEnum::CONFLICT => response()->json(['message' => HttpStatusCodeEnum::CONFLICT->getMessage()], Response::HTTP_CONFLICT),
            HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_NOT_FOUND),
            HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
            default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
        };
    }

    /** PUT /api/bookings-super-admin-management/update-statement/{unitId}/{bookingTypeId} - set statement, is_active, show_time (omit = keep). @param int $unitId, int $booingTypeId, UpdateStatementRequest $request @return JsonResponse */
    public function updateStatement(int $unitId,int $booingTypeId, UpdateStatementRequest $request): JsonResponse
    {
       
        $result = $this->bookingSuperAdminManagementService->updateStatement(
            unitId: $unitId,
            bookingTypeId: $booingTypeId,
            statement: $request->statement,
            isActive: $request->boolean('is_active'),
            showTime: $request->has('show_time')
                ? StatementShowTimeEnum::from((int) $request->input('show_time'))
                : null,
        );

        return match ($result) {
            HttpStatusCodeEnum::INTERNAL_SERVER_ERROR => response()->json(['message' => HttpStatusCodeEnum::INTERNAL_SERVER_ERROR->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR),
            HttpStatusCodeEnum::BAD_REQUEST => response()->json(['message' => HttpStatusCodeEnum::BAD_REQUEST->getMessage()], Response::HTTP_BAD_REQUEST),
            HttpStatusCodeEnum::OK => response()->json(['message' => 'שינויים עודכנו בהצלחה'], Response::HTTP_OK),
            HttpStatusCodeEnum::NOT_FOUND => response()->json(['message' => HttpStatusCodeEnum::NOT_FOUND->getMessage()], Response::HTTP_BAD_REQUEST),
            default => response()->json(['message' => HttpStatusCodeEnum::NO_CONTENT->getMessage()], Response::HTTP_NO_CONTENT)
        };
    }
}

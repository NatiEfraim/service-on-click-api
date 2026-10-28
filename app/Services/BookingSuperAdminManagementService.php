<?php

namespace App\Services;

use App\Enums\BookingTypeEnum;
use App\Enums\FormFieldEnum;
use App\Enums\HttpStatusCodeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\PaginationEnum;
use App\Enums\PayloadSectionEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\StatementShowTimeEnum;
use App\Enums\UnitEnum;
use App\Http\Resources\IndexSelectsResource;
use App\Http\Resources\SelectOptionResource;
use App\Http\Resources\UserWithPermissionIconResource;
use App\Models\BookingTypeHasStatement;
use App\Models\BookingType;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;


class BookingSuperAdminManagementService
{
    protected array $selectedFieldTypeIds;
    const MIN_LEN = 1;
    const MAX_LEN = 9;


    public function __construct(private readonly StorageService $storageService)
    {
        $this->selectedFieldTypeIds = [FieldTypeEnum::SELECT->value, FieldTypeEnum::RADIO->value, FieldTypeEnum::CHECKBOX->value, FieldTypeEnum::MULTI_SELECT->value];
    }


    public function assignAdminRole(array $validated = []): mixed
    {
        try {

            DB::beginTransaction();

            $user = $this->resolveAdminUser(validated: $validated);

            if (is_null($user)) {
                DB::rollBack();
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            foreach ($validated['role_assignments'] as $assignment) {
                $bookingTypeId = $assignment['booking_type_id'];
                $isAdmin = $assignment['is_admin'];

                $adminRole = Role::where('id', RoleEnum::ADMIN->value)->first();
                $permission = Permission::where('name', PermissionEnum::ADMIN->value . $bookingTypeId)->first();

                if (!$adminRole || !$permission) {
                    DB::rollBack();
                    return HttpStatusCodeEnum::UNPROCESSABLE_ENTITY;
                }

                if ($isAdmin) {
                    $user->assignRole($adminRole);
                    $user->givePermissionTo($permission);
                } else {
                    $user->revokePermissionTo($permission);
                }
            }
            $this->cleanupRolePermissions(user: $user, rolePrefix: PermissionEnum::ADMIN->trimSuffix());
            DB::commit();
            return $this->getAdminsWithPermission(identifier: $user->personal_number);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());          
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Paginate admin users, optionally searched by personal number or full name prefix. @param ?string $identifier @return mixed */
    public function getAdmins(?string $identifier = null): mixed
    {
        try {

            $query = User::role(RoleEnum::ADMIN->value)
                ->select('uuid', 'full_name', 'personal_number');

            if (!is_null($identifier)) {
                $identifier = str_replace(' ', '', $identifier);

                if (ctype_digit($identifier)) {
                    if (strlen($identifier) < self::MIN_LEN || strlen($identifier) > self::MAX_LEN) {
                        return HttpStatusCodeEnum::UNPROCESSABLE_ENTITY;
                    }
                    $query->where('personal_number', 'like', $identifier . '%');
                } else {
                    $query->whereRaw("REPLACE(full_name, ' ', '') LIKE ?", [$identifier . '%']);
                }
            }

            $admins = $query->paginate(PaginationEnum::PER_PAGE->value);
            $admins = $admins->map(function ($admin): array {
                return [
                    'uuid' => $admin->uuid,
                    'full_name' => $admin->full_name,
                    'personal_number' => $admin->personal_number,
                ];
            });
            return $admins->toArray();
     
        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
       
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** List in-house booking types with the user's is_admin flag for each. @param string $userUuid @return array|HttpStatusCodeEnum */
    public function getAdminPermissions(string $userUuid): array|HttpStatusCodeEnum
    {
        try {

            $user = User::where('uuid', $userUuid)->with(['roles', 'permissions'])
                ->where('is_deleted', false)->first();

            if (!$user) {
                return HttpStatusCodeEnum::BAD_REQUEST;
            }


            $bookingTypes = BookingType::where('is_outsource',false)
             ->where('id', '!=', BookingTypeEnum::EQUIPMENT_BOOKING->getBookingTypeId())
            ->get();
 
            $result = $bookingTypes->map(function ($bookingType) use ($user): array {

                $bookingTypeId = $bookingType['id'];
                return [
                    'booking_type_id' => $bookingTypeId,
                    'name' => $bookingType['name'],
                    'display_name' => 'ניהול ' . $bookingType['display_name'],
                    'icon' => $bookingType['icon'] ?? null,
                    'is_admin' => optional($user)->hasPermissionTo(PermissionEnum::ADMIN->value . $bookingTypeId) ?? false,
                ];
            });

            return [
                'role_options' => [
                    'admin' => RoleEnum::ADMIN->value,
                ],
                'user' => [
                    'full_name' => $user->full_name,
                    'personal_number' => $user->personal_number,
                ],
                'permissions' => $result->toArray()
            ];
        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
      
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Get the unit's selectable form fields (with options) for a booking type route name. @param int $unitId, string $bookingType @return array|HttpStatusCodeEnum */
    public function indexSelects(int $unitId, string $bookingType): array|HttpStatusCodeEnum
    {
        try {

            $bookingTypeId = BookingTypeEnum::getBookingTypeIdFromRouteName($bookingType);

            if (is_null($bookingTypeId)) {
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            $bookingTypeRecord = BookingTypeEnum::getBookingTypeRecord($bookingTypeId);

            if (!$bookingTypeRecord) {
                return HttpStatusCodeEnum::NOT_FOUND;
            }

          
            $relations = [
                'fieldType',
                'optionField.childOptionField',

                'childFormFields.fieldType',
                'childFormFields.optionField.childOptionField',

                'childFormFields.childFormFields.fieldType',
                'childFormFields.childFormFields.optionField.childOptionField',

                'childFormFields.childFormFields.childFormFields.fieldType',
                'childFormFields.childFormFields.childFormFields.optionField.childOptionField',
            ];

            $fields = FormFieldEnum::getSelectableRootFormFields(
                unitId: $unitId,
                bookingTypeId: $bookingTypeRecord->id,
                relations: $relations
            );

    
            return (new IndexSelectsResource([
                'fields' => $fields,
                'booking_type' => $bookingTypeRecord,
            ]))->resolve();

        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Retrieve list of formField records with field_options by received formFieldId. */
    public function getSelectOptions(int $formFieldId): array|HttpStatusCodeEnum
    {
        try {

            $formField = FormField::query()
                ->where('id', $formFieldId)
                ->first();

            if (!$formField) {
                return HttpStatusCodeEnum::NOT_FOUND;
            }

            $optionFields = OptionField::query()
                ->with([
                    'fieldType',
                    'parentOptionField',

                    'childOptionField.fieldType',
                    'childOptionField.childOptionField.fieldType',
                    'childOptionField.childOptionField.childOptionField.fieldType',
                ])
                ->where('form_field_id', $formFieldId)
                ->whereNull('option_field_id')
                ->get();

            return SelectOptionResource::collection($optionFields)
                ->resolve();
 
        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** Retrieve list of option_field records for a given formFieldId, optionally filtered by parent. */
    public function getOptions(int $formFieldId=null,int $optionFieldId=null): array|HttpStatusCodeEnum
    {
        try {


 
            $optionFields = OptionField::with([
                'fieldType',
    
            ])
            ->withCount('childOptionField') 
                ->where('form_field_id', $formFieldId)
                ->get();
         
            if (is_null($optionFieldId)) {

                $result = $optionFields
                    ->filter(fn($option) => is_null($option->option_field_id))
                    ->map(function ($option) {
                        return [
                            'option_id' => $option->id,
                            'option_name' => $option->name,
                            'field_type' => [
                                'id' =>$option->fieldType?->id,
                                'name' =>$option->fieldType?->name,
                            ],
                            'field_type_id' => $option->fieldType?->id,
                            'has_category' => $option->has_category,
                            'has_children' => $option->child_option_field_count > 0, 
                            'form_field_id' => $option->form_field_id,
                            'parent_option_id' => $option->option_field_id,
                        ];
                    });

            } else {

                $result = $optionFields
                    ->filter(fn($option) => $option->option_field_id === $optionFieldId)
                    ->map(function ($option) {
                        return [
                            'option_id' => $option->id,
                            'option_name' => $option->name,
                            'field_type' => [
                                'id' =>$option->fieldType?->id,
                                'name' =>$option->fieldType?->name,
                            ],
                            'field_type_id' => $option->fieldType?->id,
                            'has_category' => $option->has_category,
                            'has_children' => $option->child_option_field_count > 0, 
                            'form_field_id' => $option->form_field_id,
                            'parent_option_id' => $option->option_field_id,
                        ];
                    });
            }

            return $result->values()->toArray();
        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Retrieve statement of a booking type by received bookingTypeId. */
    public function getStatement(int $bookingTypeId): mixed
    {
        try {
            return BookingTypeHasStatement::where('booking_type_id', $bookingTypeId)
                ->select('id', 'statement', 'is_active', 'show_time')->first();
        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** Retrieve all option_field records that are siblings of the given optionFieldId. */
    public function getSiblingsOptions(int $optionFieldId): array|HttpStatusCodeEnum
    {
        try {

            $optionField = OptionField::where('id', $optionFieldId)->first();

            if (is_null($optionField->option_field_id)) {
                $siblings = OptionField::with(['fieldType'])
                    ->where('form_field_id', $optionField->form_field_id)
                    ->whereNull('option_field_id')
                    ->get();

                $formFieldName = FormField::where('id', $optionField->form_field_id)->first()->name;

                $parent = [
                    'id' => null,
                    'name' => $formFieldName,
                ];

            } else {

                $siblings = OptionField::with(['fieldType'])
                    ->where('option_field_id', $optionField->option_field_id)
                    ->where('form_field_id', $optionField->form_field_id)
                    ->get();

                $parentOptionField = OptionField::where('id', $optionField->option_field_id)
                    ->where('form_field_id', $optionField->form_field_id)
                    ->first();

                $parent = [
                    'id' => $parentOptionField->id,
                    'name' => $parentOptionField->name,
                ];
            }

            $data = $siblings->map(function ($sibling) {
                return [
                    'option_id' => $sibling->id,
                    'option_name' => $sibling->name,
                    'field_type' => optional($sibling->fieldType)->name ?? null,
                    'field_type_id' => $sibling->field_type_id,
                    'has_category' => (bool) $sibling->has_category,
                    'form_field_id' => $sibling->form_field_id,
                    'parent_option_id' => $sibling->option_field_id,
                ];
            });

            return [
                'parent' => $parent,
                'siblings' => $data->toArray(),
            ];


        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Update options string value by formFieldId records by id. */
    public function updateSelectOptions(int $formFieldId, array $fieldOptions = []): array|HttpStatusCodeEnum
    {
        try {

            $SelectedFieldTypes = [
                FieldTypeEnum::SELECT->value,
                FieldTypeEnum::RADIO->value,
                FieldTypeEnum::CHECKBOX->value,
                FieldTypeEnum::MULTI_SELECT->value,
            ];

            DB::beginTransaction();
                        
            foreach ($fieldOptions as $optionData) {
                $this->saveOrUpdateOptionRecursively(data: $optionData, formFieldId: $formFieldId,parentId: null,SelectedFieldTypes: $SelectedFieldTypes);
            }
         
            DB::commit();
            return HttpStatusCodeEnum::OK;

        } catch (\Exception $e) {
            Log::error($e->getMessage()); 
            DB::rollBack();
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Sync option_fields tree for a formFieldId. */
    public function updateOptions(int $formFieldId, array $fieldOptions = []): array|HttpStatusCodeEnum
    {
        try {
           
            DB::beginTransaction();
            $roots = $fieldOptions['option_fields'] ?? $fieldOptions ?? [];
            if (!is_array($roots))
                $roots = [];

            foreach ($roots as $node) {
                $this->syncOptionRecursively(options: $node, formFieldId: $formFieldId, parentId: null);
            }


            DB::commit();
            return HttpStatusCodeEnum::OK;

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollBack();
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** Update a single option_field record. */
    public function updateOption(int $formFieldId, array $fieldOptions = []): Collection|HttpStatusCodeEnum|OptionField|array
    {
        try {

            $formField = FormField::query()
                ->where('id', $formFieldId)
                ->first();

            if (!$formField) {
                return HttpStatusCodeEnum::NOT_FOUND;
            }

            $optionId = $fieldOptions['option_id'] ?? null;
            $optionName = $fieldOptions['option_name'] ?? null;
            $fieldTypeId = $fieldOptions['field_type_id'] ?? null;
            $parentOptionId = $fieldOptions['parent_option_id'] ?? null;
            $hasCategory = (int) ($fieldOptions['has_category'] ?? 0);


            DB::beginTransaction();

            $option = null;

            if (!empty($optionId)) {
                $option = OptionField::query()
                    ->where('id', $optionId)
                    ->where('form_field_id', $formFieldId)
                    ->first();

                if (!$option) {
                    DB::rollBack();
                    return HttpStatusCodeEnum::NOT_FOUND;
                }
            }

            $parentOption = null;

            if (!is_null($parentOptionId)) {
                $parentOption = OptionField::query()
                    ->where('id', $parentOptionId)
                    ->where('form_field_id', $formFieldId)
                    ->first();

                if (!$parentOption) {
                    DB::rollBack();

                    return HttpStatusCodeEnum::BAD_REQUEST;
                }
            }

            $hasChildren = $option
                ? $option->childOptionField()->exists()
                : false;

            $resolvedFieldTypeId = $this->resolveOptionFieldTypeId(
                fieldTypeId: $fieldTypeId,
                hasCategory: $hasCategory,
                hasChildren: $hasChildren,
            );

            if (
                $option
                && (int) $resolvedFieldTypeId === FieldTypeEnum::TEXT->value
                && $hasChildren
            ) {
                DB::rollBack();

                return HttpStatusCodeEnum::CONFLICT;
            }

            $isInputProvided = array_key_exists('is_input', $fieldOptions);
            $requestedIsInput = $isInputProvided ? (bool) $fieldOptions['is_input'] : null;

            // An input option carries a typed value, so it can only be a text or a number field.
            if (
                $requestedIsInput === true
                && !in_array((int) $resolvedFieldTypeId, [FieldTypeEnum::TEXT->value, FieldTypeEnum::NUMBER->value], true)
            ) {
                DB::rollBack();

                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            $payloadSectionId = $this->resolveOptionPayloadSectionId(
                formField: $formField,
                hasCategory: $hasCategory,
                hasChildren: $hasChildren,
                fieldTypeId: $resolvedFieldTypeId,
                isInput: $requestedIsInput,
            );

            $isInput = $isInputProvided
                ? $requestedIsInput
                : (int) $payloadSectionId === PayloadSectionEnum::ITEMS->value;

            if ($option) {
                $option->update([
                    'name' => trim($optionName),
                    'has_category' => $hasCategory,
                    'field_type_id' => $resolvedFieldTypeId,
                    'option_field_id' => $parentOptionId,
                    'form_field_id' => $formFieldId,
                    'payload_section_id' => $payloadSectionId,
                    'is_input' => $isInput,
                ]);
            } else {
                $option = OptionField::create([
                    'uuid' => Str::uuid(),
                    'name' => trim($optionName),
                    'has_category' => $hasCategory,
                    'field_type_id' => $resolvedFieldTypeId,
                    'option_field_id' => $parentOptionId,
                    'form_field_id' => $formFieldId,
                    'payload_section_id' => $payloadSectionId,
                    'is_input' => $isInput,
                ]);
            }


            if (!is_null($parentOptionId)) {
                $this->convertParentOptionToWrapper(
                    parentOptionId: (int) $parentOptionId,
                    formFieldId: $formFieldId,
                );
            }

            DB::commit();

            return [
                'option_id' => $option->id,
                'uuid' => $option->uuid,
                'option_name' => $option->name,
                'field_type_id' => $option->field_type_id,
                'has_category' => (bool) $option->has_category,
                'form_field_id' => $option->form_field_id,
                'parent_option_id' => $option->option_field_id,
                'payload_section_id' => $option->payload_section_id,
                'is_input' => (bool) $option->is_input,
            ];

        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** Remove options recursively by formFieldId records by id. */
    public function removeSelectOptions(int $formFieldId, array $fieldOptions = []): array|HttpStatusCodeEnum
    {
        try {
    
            $SelectedFieldTypes = [
                FieldTypeEnum::SELECT->value,
                FieldTypeEnum::RADIO->value,
                FieldTypeEnum::CHECKBOX->value,
                FieldTypeEnum::MULTI_SELECT->value,
            ];

            DB::beginTransaction();
                        
            foreach ($fieldOptions as $optionData) {
                $this->removeOptionRecursively(data: $optionData, formFieldId: $formFieldId,SelectedFieldTypes: $SelectedFieldTypes);
            }
         
            DB::commit();
            return HttpStatusCodeEnum::OK;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** Remove a single option record by id. */
    public function removeOption(int $formFieldId, array $fieldOptions = []): array|HttpStatusCodeEnum
    {
        try {
         
      
            $fieldOptions = [$fieldOptions]; 
                  
            foreach ($fieldOptions as $optionData) {
             
                $res= $this->removeLeafOption($formFieldId,$optionData['option_id']);
                if ($res!= HttpStatusCodeEnum::OK) {
                     return HttpStatusCodeEnum::CONFLICT;
                }
            }
            return HttpStatusCodeEnum::OK;

        } catch (\Exception $e) {
                Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    /** Upsert a unit's booking type statement; null $showTime keeps the current value. @param int $unitId, int $bookingTypeId, ?string $statement, bool $isActive, ?StatementShowTimeEnum $showTime @return array|HttpStatusCodeEnum */
    public function updateStatement(
        int $unitId,
        int $bookingTypeId,
        ?string $statement,
        bool $isActive = false,
        ?StatementShowTimeEnum $showTime = null,
    ): array|HttpStatusCodeEnum
    {
        try {

            if (is_null($statement)&& $isActive) {
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            DB::beginTransaction();

            BookingTypeHasStatement::updateOrCreate(
                [
                    'unit_id' => $unitId,
                    'booking_type_id' => $bookingTypeId,

                ],
                array_filter([
                    'is_active' => $isActive,
                    'statement' => $statement ?? 'לא קיימת הצהרה',
                    'show_time' => $showTime,
                ], fn($value) => !is_null($value))
            );

            DB::commit();

            return HttpStatusCodeEnum::OK;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

    //---------------------------- private functions ---------------------------------//

    /** Fetch the user by uuid or personal number, creating one when no uuid is sent and none exists. @param array $validated @return ?User */
    private function resolveAdminUser(array $validated): ?User
    {
        if (!empty($validated['uuid'])) {
            return User::with(['roles', 'permissions'])
                ->where('uuid', $validated['uuid'])
                ->first();
        }

        $personalNumber = $validated['personal_number'] ?? null;
        $unitId = $validated['unit_id'] ?? null;

        if (is_null($personalNumber) || is_null(UnitEnum::tryFrom((int) $unitId))) {
            return null;
        }

        $user = User::with(['roles', 'permissions'])
            ->where('personal_number', $personalNumber)
            ->first();

        if (!is_null($user)) {
            return $user;
        }

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => $validated['full_name'] ?? null,
            'personal_number' => $personalNumber,
            'phone' => $validated['phone'] ?? null,
            'unit_id' => (int) $unitId,
            'is_deleted' => false,
        ]);

        return $user->load(['roles', 'permissions']);
    }

    private function resolveOptionFieldTypeId(
        mixed $fieldTypeId,
        int $hasCategory,
        bool $hasChildren,
    ): int {
        if (!is_null($fieldTypeId) && is_numeric($fieldTypeId)) {
            return (int) $fieldTypeId;
        }

        if ($hasCategory === 1 || $hasChildren) {
            return FieldTypeEnum::SELECT->value;
        }

        return FieldTypeEnum::TEXT->value;
    }

    private function resolveOptionPayloadSectionId(
        FormField $formField,
        int $hasCategory,
        bool $hasChildren,
        int $fieldTypeId,
        ?bool $isInput = null,
    ): int {

        if (
            $hasCategory === 1
            || $hasChildren
            || $this->isWrapperOptionFieldType($fieldTypeId)
        ) {
            return PayloadSectionEnum::NONE->value;
        }


        // A free-text option carries its typed value, so it always travels as an item.
        if ($isInput === true) {
            return PayloadSectionEnum::ITEMS->value;
        }

        return match ((int) $formField->booking_type_id) {

            BookingTypeEnum::CAR_BOOKING->getBookingTypeId(),
            BookingTypeEnum::CAR_MAINTENANCE_BOOKING->getBookingTypeId(),
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId(),
            BookingTypeEnum::CONSTRUCTION_BOOKING->getBookingTypeId(),
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId()
            => PayloadSectionEnum::SELECTS->value,

            // Out-unit food: the sandwiches / trays choice is a select, the sensitivities are quantity items.
            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId()
            => in_array((int) $formField->id, [
                FormFieldEnum::OUT_UNIT_SANDWICHES->getFormFieldId(),
                FormFieldEnum::OUT_UNIT_TRAYS->getFormFieldId(),
            ], true)
                ? PayloadSectionEnum::SELECTS->value
                : PayloadSectionEnum::ITEMS->value,

            BookingTypeEnum::EQUIPMENT_BOOKING_MALE->getBookingTypeId(),
            BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE->getBookingTypeId()
            => PayloadSectionEnum::ITEMS->value,

            default => PayloadSectionEnum::NONE->value,
        };
    }
    private function isWrapperOptionFieldType(int $fieldTypeId): bool
    {
        return in_array($fieldTypeId, [
            FieldTypeEnum::SELECT->value,
            FieldTypeEnum::MULTI_SELECT->value,
        ], true);
    }

    private function convertParentOptionToWrapper(
        int $parentOptionId,
        int $formFieldId,
    ): void {
        $parent = OptionField::query()
            ->where('id', $parentOptionId)
            ->where('form_field_id', $formFieldId)
            ->first();

        if (!$parent) {
            return;
        }

        $parent->update([
            'has_category' => true,
            'field_type_id' => FieldTypeEnum::SELECT->value,
            'payload_section_id' => PayloadSectionEnum::NONE->value,
            'is_input' => false,
        ]);
    }



    /** Update parent option field after deleting one of its children (no more children -> becomes a leaf). */
    private function updateParentAfterChildDelete(int $parentOptionId=null, int $formFieldId=null): bool
    {


        $parentOption = OptionField::where('id', $parentOptionId)
            ->where('form_field_id', $formFieldId)
            ->first();

        if (!$parentOption) {
            return true;
        }

        $stillHasChildren = OptionField::where('option_field_id', $parentOptionId)
            ->where('form_field_id', $formFieldId)
            ->exists();

        if (!$stillHasChildren) {
            $parentOption->update([
                'has_category' => false,
                'field_type_id' => FieldTypeEnum::TEXT->value, 
            ]);
        }
        return true;


    }


    /** Remove a leaf option field (only if it has no children); updates the parent afterward. */
    public function removeLeafOption(int $formFieldId, int $optionId): HttpStatusCodeEnum
    {
        try {

            $optionField = OptionField::where('id', $optionId)
                ->where('form_field_id', $formFieldId)
                ->first();

            if (!$optionField) {
                return HttpStatusCodeEnum::BAD_REQUEST;
            }

            $parentOptionField = OptionField::where('id', $optionField->option_field_id)
                ->where('form_field_id', $formFieldId)
                ->first();

            if (!$parentOptionField) {//remove optionField Root
                $hasOtherRootOptions = OptionField::where('form_field_id', $formFieldId)
                    ->whereNull('option_field_id')
                    ->exists();
                if (is_null($hasOtherRootOptions)) {
                    return HttpStatusCodeEnum::CONFLICT;
                }
                $optionField->delete();
                return HttpStatusCodeEnum::OK;
            }

            $parentOptionId = $optionField->option_field_id;

            $optionField->delete();

            if (!is_null($parentOptionId)) {
                $this->updateParentAfterChildDelete($parentOptionId, $formFieldId);
            }

            return HttpStatusCodeEnum::OK;

        } catch (\Exception $e) {
              Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }


    /** Recursively create or update option fields (and their children). */
    public function syncOptionRecursively(array $options, ?int $formFieldId = null, ?int $parentId = null): void
    {
        
        try {

            $children = $options['option_fields'] ?? [];
            $hasChildren = is_array($children) && count($children) > 0;
            $hasCategory = (int) ($options['has_category'] ?? 0);
            $optionName = $options['option_name'] ?? null;
            $optionId = $options['option_id'] ?? null;

            $fieldType = ($hasCategory === 1 && $hasChildren)
                ? FieldTypeEnum::SELECT->value
                : FieldTypeEnum::TEXT->value;

            $option = null;
            if (!empty($optionId)) {
                $option = OptionField::where('id', $optionId)->first(); 
            }
            
            if ($option) {
                $option->update([
                    'name' => $optionName ?? $option->name,
                    'field_type_id' => $fieldType,
                    'has_category' => $hasCategory,
                    'form_field_id' => $formFieldId,
                    'option_field_id' => $parentId,
                ]);
            } else {
                $option = OptionField::create([
                    'uuid' => Str::uuid(),
                    'name' => $optionName,
                    'field_type_id' => $fieldType,
                    'has_category' => $hasCategory,
                    'form_field_id' => $formFieldId,
                    'option_field_id' => $parentId,
                ]);
            }

            if ($hasChildren) {
                foreach ($children as $childNode) {
                    $this->syncOptionRecursively($childNode, $formFieldId, $option->id);
                }
            }

        } catch (\Exception $e) {
            Log::error('Error in BookingSuperAdminManagementService: syncOptionFields function:' . $e->getMessage());
            throw $e;
        }
    }

    /** Remove a role from the user if they hold no permissions for it. */
    private function cleanupRolePermissions(User $user, string $rolePrefix): void
    {

        $role = Role::where('name', $rolePrefix)->first();

        if ($role) {

            $hasPermissions = $user->permissions->contains(function ($permission) use ($rolePrefix) {

                return str_starts_with($permission->name, $rolePrefix . '_');
            });

            if (!$hasPermissions) {
                $user->removeRole($role);
            }
        }
    }

    /** Recursively build the tree of option fields with 'category_fields' key. */
    private function buildOptionFieldTreeFromService($option): array
    {
        $optionData = [
            'option_id' => $option->id,
            'option_name' => $option->name,
            'field_type' => $option->fieldType?->name,
            'has_category' => $option->has_category,
        ];

        if (!is_null($option->option_field_id)) {
            $optionData['parent_option_id'] = $option->option_field_id;
        }

        if ($option->category_name) {
            $optionData['category_name'] = $option->category_name;
        }

        if ($option->has_category && $option->childOptionField->isNotEmpty()) {
            $optionData['option_fields'] = $option->childOptionField->map(function ($childOption) {
                return $this->buildOptionFieldTreeFromService($childOption);
            })->filter()->values()->all();
        }

        return $optionData;
    }


    /** Recursively handle updating or creating an option. */
    private function saveOrUpdateOptionRecursively(array $data =[], int $formFieldId, ?int $parentId = null,array $SelectedFieldTypes=[]): void
    {

        $data['option_field_id'] = $data['parent_option_id'] ?? $parentId;
        $option = isset($data['option_id'])
            ? OptionField::where('id',$data['option_id'])->first() ?? null
            : null;
    

        if (is_null($option)) {
            //create new option
          $option=OptionField::create([
                'uuid' => Str::uuid(),
                'name' => $data['option_name'],
                'form_field_id' => $formFieldId,
                'has_category' => $data['has_category'] ?? false,
                'option_field_id' => $data['option_field_id'],
                'field_type_id' => $data['has_category'] && isset($data['option_fields']) ? FieldTypeEnum::SELECT->value : FieldTypeEnum::TEXT->value
            ]);

        }else {
            $option->update([
                'name' => $data['option_name']?? $option->name,
                'has_category' => $data['has_category'] ?? false,
                'field_type_id' => $data['has_category'] && isset($data['option_fields']) ? FieldTypeEnum::SELECT->value : FieldTypeEnum::TEXT->value
            ]); 
        }

        if (
            $data['has_category']
            &&
            isset($data['option_fields'])
            &&
            is_array($data['option_fields'])
            && in_array($option->field_type_id,$this->selectedFieldTypeIds)
        ) { 
            foreach ($data['option_fields'] as $childData) {
             
                $this->saveOrUpdateOptionRecursively(
                    data: $childData,
                    formFieldId: $formFieldId,
                    parentId: $option->id,
                    SelectedFieldTypes: $SelectedFieldTypes
                );
            }
        }
  
        return;
    }


    /** Recursively handle removing an option. */
    private function removeOptionRecursively(array $data = [], int $formFieldId, array $SelectedFieldTypes = [], ?int $parentId = null): mixed
    {


        $data['option_field_id'] = $data['parent_option_id'] ?? $parentId;

        $optionField = OptionField::with([
            'fieldType',
            'parentOptionField',
            'childOptionField.fieldType',
            'childOptionField.childOptionField',
        ])->where('id', $data['option_id'])->where('form_field_id', $formFieldId)->first();

        if (is_null($optionField)) {
            return true;
        }
        $hasChildren = $optionField->childOptionField()->exists();

    if ($hasChildren) {
        return false;
    }
       

        if (in_array($optionField->field_type_id, $this->selectedFieldTypeIds, true)) {
            foreach ($optionField->childOptionField as $child) {
                $this->removeOptionRecursively(
                    data: [
                        'option_id' => $child->id,
                        'parent_option_id' => $optionField->id,
                    ],
                    formFieldId: $formFieldId,
                    SelectedFieldTypes: $SelectedFieldTypes,
                    parentId: $optionField->id
                );
            }
        }
        $parentOptionId = $optionField->option_field_id;
        $optionField->delete();
        if (!is_null($parentOptionId)) {
            $this->updateParentAfterChildDelete($parentOptionId, $formFieldId);
        }
        return true;
    }

    /** Retrieve all admin users with their related admin permissions. */
    private function getAdminsWithPermission(?string $identifier = null): HttpStatusCodeEnum|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|array
    {
        try {

            $users = User::whereHas('roles', function ($query) {
                $query->whereIn('id', [RoleEnum::ADMIN->value]);
            })
                ->when($identifier, function ($query, $identifier) {
                    $query->where(function ($q) use ($identifier) {
                        $q->where('personal_number', 'like', $identifier . '%')
                            ->orWhere('full_name', 'like', $identifier . '%');
                    });
                })
                ->with([
                    'roles',
                    'permissions' => function ($query) {
                        $query->where(function ($q) {
                            $q->where('name', 'like', PermissionEnum::ADMIN->value . '%');
                        });
                    }
                ])->paginate(PaginationEnum::PER_PAGE->value);

            return UserWithPermissionIconResource::collection($users);
        } catch (\Exception $e) {
               Log::error($e->getMessage()); 
            $this->storageService->logToS3();
            return HttpStatusCodeEnum::INTERNAL_SERVER_ERROR;
        }
    }

}

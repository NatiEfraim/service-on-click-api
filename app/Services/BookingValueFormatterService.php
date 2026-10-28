<?php

namespace App\Services;


use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Models\Booking;
use App\Models\File;
use App\Models\FormField;
use App\Models\Image;
use App\Models\OptionField;
use App\Enums\PermissionEnum;
use App\Enums\StatusBookingEnum;
use App\Http\Resources\FormFieldResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BookingValueFormatterService
{
    /** Create a new class instance. */
    public function __construct(private readonly StorageService $storageService)
    {
        //
    }

    /** Format a booking's values (plus uploads) for display, merging nested form field trees. @param Booking $booking @return ?array */
    public function format(Booking $booking)
    {

        try {
            $formattedValues = $booking->bookingValue
                ->groupBy('form_field_id')
                ->map(function ($bookingValuesByFormField) use ($booking) {
                    $firstBookingValue = $bookingValuesByFormField->first();
                    $formField = $firstBookingValue->formField;

                    if (!$formField) {
                        return null;
                    }

                    $optionRows = $bookingValuesByFormField
                        ->filter(fn($bookingValue) => !is_null($bookingValue->option_field_id))
                        ->values();

                    if ($optionRows->isNotEmpty()) {
                        return $this->formatOptionBookingValues(
                            booking: $booking,
                            bookingValues: $optionRows,
                            formField: $formField
                        );
                    }
                    $decodedValue = $this->decodeBookingValue($firstBookingValue->value);

                    $normalizedValue = FieldTypeEnum::normalize(
                        fieldTypeValue: (int) $formField->field_type_id,
                        value: $decodedValue
                    );

                    return $this->resolveBookingValuePayload(
                        booking: $booking,
                        formField: $formField,
                        regularPayload: [
                            'id' => $firstBookingValue->id,
                            'form_field_id' => $formField->id,
                            'field_uuid' => $formField->uuid,
                            'field_name' => $formField->field_name,
                            'field_display_name' => $formField->field_display_name,
                            'editable' => $this->isEditable($booking, $formField),
                            'field_type' => $this->retreiveFieldTypeObject($booking, $formField),
                            'field_restrictions' => $this->resolveFieldRestrictions($formField),
                            'value' => $normalizedValue,
                        ],
                        value: $decodedValue
                    );
                })
                ->filter()
                ->values()
                ->all();

            $formattedValues = $this->appendUploadFormFieldValues($booking, $formattedValues);

            return $this->mergeNestedFormFieldTrees($formattedValues);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->storageService->logToS3();
        }

    }

    /** Append one entry per upload form field holding files/images; untagged uploads go to root upload fields. @param Booking $booking, array $formattedValues @return array */
    private function appendUploadFormFieldValues(Booking $booking, array $formattedValues): array
    {
        $files = $booking->files;
        $images = $booking->images;

        if ($files->isEmpty() && $images->isEmpty()) {
            return $formattedValues;
        }

        $uploadFormFields = FormField::query()
            ->with('parentFormField')
            ->where('booking_type_id', $booking->booking_type_id)
            ->whereIn('field_type_id', FieldTypeEnum::uploadFieldTypeIds())
            ->get();

        foreach ($uploadFormFields as $formField) {
            $belongsToFormField = fn($upload) => (int) $upload->pivot->form_field_id === (int) $formField->id
                || (is_null($upload->pivot->form_field_id) && is_null($formField->form_field_id));

            $payload = [
                ...$this->formatFilesPayload($files->filter($belongsToFormField)),
                ...$this->formatImagesPayload($images->filter($belongsToFormField)),
            ];

            if (empty($payload)) {
                continue;
            }

            $formattedValues[] = $this->resolveBookingValuePayload(
                booking: $booking,
                formField: $formField,
                regularPayload: [
                    'form_field_id' => $formField->id,
                    'field_uuid' => $formField->uuid,
                    'field_name' => $formField->field_name,
                    'field_display_name' => $formField->field_display_name,
                    'editable' => $this->isEditable($booking, $formField),
                    'field_type' => $this->retreiveFieldTypeObject($booking, $formField),
                    'value' => $payload,
                ],
                value: $payload
            );
        }

        return $formattedValues;
    }

    /** Format uploaded documents (File models) into a flat payload array. */
    private function formatFilesPayload(mixed $files): array
    {
        return $files
            ->map(fn(File $file) => $this->removeNullValues([
                'id' => $file->id,
                'type' => 'file',
                'name' => $file->file_name,
                'display_name' => $file->display_name,
                'url' => $file->path ? $this->storageService->generateFileTemporaryUrl($file->path) : null,
                'size' => $file->size ?? null,
            ]))
            ->values()
            ->all();
    }

    /** Format uploaded images (Image models) into a flat payload array. */
    private function formatImagesPayload(mixed $images): array
    {
        return $images
            ->map(fn(Image $image) => $this->removeNullValues([
                'id' => $image->id,
                'type' => 'image',
                'name' => $image->file_name,
                'url' => $image->path ? $this->storageService->generateImageTemporaryUrl($image->path) : null,
            ]))
            ->values()
            ->all();
    }

    /** Same field restrictions as the create form; null unless systemReferences is eager-loaded. @param FormField $formField @return ?array */
    private function resolveFieldRestrictions(FormField $formField): ?array
    {
        if (!$formField->relationLoaded('systemReferences')) {
            return null;
        }

        return (new FormFieldResource($formField))->resolveFieldRestrictions();
    }

    /** Check if the form field is editable for the current user and booking status. @param Booking $booking, mixed $formField @return bool */
    private function isEditable(Booking $booking, mixed $formField): bool
    {
        $user = Auth::user();

        if (!$user || !$formField) {
            return false;
        }

        if (!(bool) $formField->editable) {
            return false;
        }

        $bookingTypeId = (int) $booking->booking_type_id;
        $statusId = (int) $booking->status_id;

        $viceApproverPermission = PermissionEnum::VICE_APPROVER->value . $bookingTypeId;
        $transportationControlOfficerPermission = PermissionEnum::TRANSPORTATION_CONTROL_OFFICER->value . $bookingTypeId;
        $approverPermission = PermissionEnum::APPROVER->value . $bookingTypeId;
        $adminPermission = PermissionEnum::ADMIN->value . $bookingTypeId;

        // Checked against the user's own permissions directly - hasPermissionTo() throws for a permission that doesn't exist.
        $userPermissionNames = $user->getAllPermissions()->pluck('name');

        $canViceApproverEdit =
            (
                $userPermissionNames->contains($viceApproverPermission)
                || $userPermissionNames->contains($transportationControlOfficerPermission)
            ) &&
            $statusId === StatusBookingEnum::RECEIVED->value;

        $hasApproverOrAdminPermission =
            $userPermissionNames->contains($approverPermission) ||
            $userPermissionNames->contains($adminPermission);

        $canApproverOrAdminEdit =
            $hasApproverOrAdminPermission &&
            $statusId < StatusBookingEnum::APPROVED->value;

        return $canViceApproverEdit || $canApproverOrAdminEdit;
    }

    /** Merge nested form field trees only with other nested form field trees. @param array $items @return array */
    private function mergeNestedFormFieldTrees(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {

            if (!$this->isFormFieldTreeNode($item)) {
                $merged[] = $item;
                continue;
            }

            $existingIndex = $this->findFormFieldTreeNodeIndex(
                items: $merged,
                item: $item,
            );

            if ($existingIndex === null) {
                $merged[] = $item;
                continue;
            }

            $merged[$existingIndex] = $this->mergeFormFieldNode(
                existing: $merged[$existingIndex],
                incoming: $item,
            );
        }

        return array_values($merged);
    }

    /** Whether the item is a nested form field tree node (uuid + field_name, no field_uuid). @param array $item @return bool */
    private function isFormFieldTreeNode(array $item): bool
    {
        return isset($item['uuid'])
            && isset($item['field_name'])
            && !isset($item['field_uuid']);
    }

    /** Find an existing tree node by form field uuid. @param array $items, array $item @return ?int */
    private function findFormFieldTreeNodeIndex(array $items, array $item): ?int
    {
        foreach ($items as $index => $existingItem) {
            if (!$this->isFormFieldTreeNode($existingItem)) {
                continue;
            }

            if (($existingItem['uuid'] ?? null) === ($item['uuid'] ?? null)) {
                return $index;
            }
        }

        return null;
    }

    /** Merge one form field node into another (option_fields, value, child form_fields recursively). @param array $existing, array $incoming @return array */
    private function mergeFormFieldNode(array $existing, array $incoming): array
    {
        // Merge option_fields.
        if (isset($incoming['option_fields'])) {
            $existing['option_fields'] = $incoming['option_fields'];
        }

        // Merge value.
        if (array_key_exists('value', $incoming)) {
            $existing['value'] = $incoming['value'];
        }

        // Merge form_fields recursively.
        if (isset($incoming['form_fields']) && is_array($incoming['form_fields'])) {
            $existing['form_fields'] ??= [];

            foreach ($incoming['form_fields'] as $incomingChild) {
                if (!isset($incomingChild['id'])) {
                    $existing['form_fields'][] = $incomingChild;
                    continue;
                }

                $existingChildIndex = $this->findItemIndexById(
                    items: $existing['form_fields'],
                    id: (int) $incomingChild['id']
                );

                if ($existingChildIndex === null) {
                    $existing['form_fields'][] = $incomingChild;
                    continue;
                }

                $existing['form_fields'][$existingChildIndex] = $this->mergeFormFieldNode(
                    existing: $existing['form_fields'][$existingChildIndex],
                    incoming: $incomingChild
                );
            }
        }

        return $existing;
    }

    /** Find child form field node index by form field id, inside nested form field trees. */
    private function findItemIndexById(array $items, int $id): ?int
    {
        foreach ($items as $index => $item) {
            if (!$this->isFormFieldTreeNode($item)) {
                continue;
            }

            if ((int) ($item['id'] ?? 0) === $id) {
                return $index;
            }
        }

        return null;
    }

    /** Format option based booking values - depth comes from option_field_id, not the booking type. */
    private function formatOptionBookingValues(
        Booking $booking,
        mixed $bookingValues,
        FormField $formField
    ): array {
        $optionFieldsPayload = $this->buildOptionFieldsPayload(
            bookingValues: $bookingValues,
            formField: $formField,
        );

        return $this->resolveBookingValuePayload(
            booking: $booking,
            formField: $formField,
            regularPayload: [
                'id' => $bookingValues->first()->id,
                'form_field_id' => $formField->id,
                'field_uuid' => $formField->uuid,
                'field_name' => $formField->field_name,
                'field_display_name' => $formField->field_display_name,
                'editable' => $this->isEditable($booking, $formField),
                'field_type' => $this->retreiveFieldTypeObject($booking, $formField),
                'option_fields' => $optionFieldsPayload,
            ],
            optionFields: $optionFieldsPayload
        );
    }

    /** Merge every selected option of one form field into a single option tree. */
    private function buildOptionFieldsPayload(mixed $bookingValues, FormField $formField): array
    {
        $nodes = [];

        foreach ($bookingValues as $bookingValue) {
            $path = $this->buildOptionFieldPath(
                optionField: $bookingValue->optionField,
                value: $this->decodeBookingValue($bookingValue->value)
            );

            if (is_null($path)) {
                continue;
            }

            $nodes = $this->mergeOptionFieldNodes($nodes, $path);
        }

        if (empty($nodes)) {
            return [];
        }

        return $this->collapseOptionFieldNodes(
            nodes: $this->collapseOptionFieldTree($nodes),
            fieldTypeId: (int) $formField->field_type_id,
        );
    }

    /** Add one option path to the merged list, joining it with the same option when already there. */
    private function mergeOptionFieldNodes(array $nodes, array $incoming): array
    {
        foreach ($nodes as $index => $node) {
            if (($node['option_id'] ?? null) !== ($incoming['option_id'] ?? null)) {
                continue;
            }

            $nodes[$index] = $this->mergeOptionFieldNode(
                existing: $node,
                incoming: $incoming,
            );

            return $nodes;
        }

        $nodes[] = $incoming;

        return $nodes;
    }

    /** Merge one option node into another, recursively merging their nested options. */
    private function mergeOptionFieldNode(array $existing, array $incoming): array
    {
        if (array_key_exists('value', $incoming)) {
            $existing['value'] = $incoming['value'];
        }

        if (!isset($incoming['option_fields'])) {
            return $existing;
        }

        $children = $this->normalizeOptionFieldNodes($existing['option_fields'] ?? []);

        foreach ($this->normalizeOptionFieldNodes($incoming['option_fields']) as $incomingChild) {
            $children = $this->mergeOptionFieldNodes($children, $incomingChild);
        }

        $existing['option_fields'] = $children;

        return $existing;
    }

    /** option_fields may hold a single option node or a list of them - always work with a list. */
    private function normalizeOptionFieldNodes(mixed $optionFields): array
    {
        if (!is_array($optionFields) || empty($optionFields)) {
            return [];
        }

        return array_is_list($optionFields)
            ? array_values($optionFields)
            : [$optionFields];
    }

    /** Collapse each tree level: one choice under a single-value field type is an object, else a list. @param array $nodes @return array */
    private function collapseOptionFieldTree(array $nodes): array
    {
        foreach ($nodes as $index => $node) {
            $children = $this->normalizeOptionFieldNodes($node['option_fields'] ?? []);

            if (empty($children)) {
                continue;
            }

            $nodes[$index]['option_fields'] = $this->collapseOptionFieldNodes(
                nodes: $this->collapseOptionFieldTree($children),
                fieldTypeId: (int) ($node['field_type']['id'] ?? 0),
            );
        }

        return $nodes;
    }

    private function collapseOptionFieldNodes(array $nodes, int $fieldTypeId): array
    {
        if (count($nodes) === 1 && !$this->isMultiValueFieldTypeId($fieldTypeId)) {
            return $nodes[0];
        }

        return array_values($nodes);
    }

    /** A category node carries nested options; a plain node carries a value. */
    private function isCategoryFieldTypeId(?int $fieldTypeId): bool
    {
        return in_array($fieldTypeId, [
            FieldTypeEnum::SELECT->value,
            FieldTypeEnum::RADIO->value,
            FieldTypeEnum::CHECKBOX->value,
            FieldTypeEnum::MULTI_SELECT->value,
        ], true);
    }

    /** Field types that may hold more than one selected option at once. */
    private function isMultiValueFieldTypeId(?int $fieldTypeId): bool
    {
        return in_array($fieldTypeId, [
            FieldTypeEnum::CHECKBOX->value,
            FieldTypeEnum::MULTI_SELECT->value,
        ], true);
    }

    /** An option opens another level when it is flagged as a category or typed as one. */
    private function isCategoryOptionField(?OptionField $optionField): bool
    {
        return $optionField
            && (
                (bool) $optionField->has_category
                || $this->isCategoryFieldTypeId((int) $optionField->field_type_id)
            );
    }

    /** A form field opens another level when it is typed as a category. */
    private function isCategoryFormField(?FormField $formField): bool
    {
        return $formField
            && $this->isCategoryFieldTypeId((int) $formField->field_type_id);
    }
    /** Return the form field tree if needed, else the regular flat payload. @param Booking $booking, FormField $formField, array $regularPayload, mixed $value, mixed $optionFields @return array */
    private function resolveBookingValuePayload(
        Booking $booking,
        FormField $formField,
        array $regularPayload,
        mixed $value = null,
        mixed $optionFields = null
    ): array {
        $formFieldTree = $this->getFormFieldTreeIfNeeded(
            booking: $booking,
            formField: $formField,
            value: $value,
            optionFields: $optionFields
        );

        if (!is_null($formFieldTree)) {
            return $formFieldTree;
        }

        return $this->removeNullValues($regularPayload);
    }


    /** Build the field tree if needed. @param Booking $booking, FormField $formField, mixed $value, mixed $optionFields @return ?array */
    private function getFormFieldTreeIfNeeded(
        Booking $booking,
        FormField $formField,
        mixed $value = null,
        mixed $optionFields = null
    ): ?array {
        if (!$this->shouldShowFormFieldTree($booking, $formField)) {
            return null;
        }

        return $this->buildSelectedFormFieldTree(
            booking: $booking,
            leafFormField: $formField,
            value: $value,
            optionFields: $optionFields
        );
    }


    /** A nested field renders as a tree whenever it hangs under a category field, or is one itself. */
    private function shouldShowFormFieldTree(Booking $booking, FormField $formField): bool
    {
        if (is_null($formField->form_field_id)) {
            return false;
        }

        return $this->isCategoryFormField($formField->parentFormField)
            || $this->isCategoryFormField($formField);
    }
    /** Build the selected form field tree from root to the selected leaf. @param Booking $booking, FormField $leafFormField, mixed $value, mixed $optionFields @return array */
    private function buildSelectedFormFieldTree(
        Booking $booking,
        FormField $leafFormField,
        mixed $value = null,
        mixed $optionFields = null
    ): array {
        $path = [];
        $current = $leafFormField;

        while ($current) {
            $path[] = $current;
            $current = $current->parentFormField;
        }

        $path = array_reverse($path);

        return $this->buildNestedFormFieldPath(
            booking: $booking,
            path: $path,
            index: 0,
            value: $value,
            optionFields: $optionFields
        );
    }


    /** Recursively build the nested form field path; the leaf gets the value or option_fields. @param Booking $booking, array $path, int $index, mixed $value, mixed $optionFields @return array */
    private function buildNestedFormFieldPath(
        Booking $booking,
        array $path,
        int $index,
        mixed $value = null,
        mixed $optionFields = null
    ): array {
        /** @var FormField $formField */
        $formField = $path[$index];

        $isLeaf = $index === array_key_last($path);

        $node = $this->formatFormFieldNode($booking, $formField);

        if ($isLeaf) {
            if (!is_null($optionFields)) {
                $node['option_fields'] = $optionFields;
            } elseif (!is_null($value)) {
                $node['value'] = FieldTypeEnum::normalize(
                    fieldTypeValue: (int) $formField->field_type_id,
                    value: $value
                );
            }

            return $this->removeNullValues($node);
        }

        $node['form_fields'] = [
            $this->buildNestedFormFieldPath(
                booking: $booking,
                path: $path,
                index: $index + 1,
                value: $value,
                optionFields: $optionFields
            ),
        ];

        return $this->removeNullValues($node);
    }

    /** Format a form field node without null keys. @param Booking $booking, FormField $formField @return array */
    private function formatFormFieldNode(Booking $booking, FormField $formField): array
    {
        return $this->removeNullValues([
            'id' => $formField->id,
            'form_field_id' => $formField->form_field_id,
            'uuid' => $formField->uuid,
            'field_name' => $formField->field_name,
            'field_display_name' => $formField->field_display_name,
            'field_type' => $this->retreiveFieldTypeObject($booking, $formField),
            'is_input' => isset($formField->is_input) ? (bool) $formField->is_input : null,
            'is_required' => isset($formField->is_required) ? (bool) $formField->is_required : null,
            'payload_section_id' => (int) $formField->payload_section_id,
            'editable' => $this->isEditable($booking, $formField),
        ]);
    }

    /** Build the ancestor chain of one selected option, from its root category down to itself. */
    private function buildOptionFieldPath(?OptionField $optionField, mixed $value = null): ?array
    {
        if (!$optionField) {
            return null;
        }

        $node = $this->formatOptionFieldNode($optionField);

        if (!is_null($value) && !$this->isCategoryOptionField($optionField)) {
            // A free-text option holds what the user typed - shown by its field type, like a plain input.
            $typedValue = $optionField->is_input
                ? FieldTypeEnum::normalize(fieldTypeValue: (int) $optionField->field_type_id, value: $value)
                : $value;

            if (!is_null($typedValue)) {
                $node['value'] = $typedValue;
            }
        }

        $parentPath = $this->buildOptionFieldPath($optionField->parentOptionField);

        if (is_null($parentPath)) {
            return $node;
        }

        $cursor = &$parentPath;

        while (isset($cursor['option_fields'][0])) {
            $cursor = &$cursor['option_fields'][0];
        }

        $cursor['option_fields'] = [$node];

        unset($cursor);

        return $parentPath;
    }
    /** Format an option field node without null keys. @param OptionField $optionField @return array */
    private function formatOptionFieldNode(OptionField $optionField): array
    {
        return $this->removeNullValues([
            'form_field_id' => $optionField->form_field_id,
            'option_id' => $optionField->id,
            'uuid' => $optionField->uuid,
            'display_name' => $optionField->name,
            'field_type' => [
                'id' => $optionField->fieldType?->id,
                'name' => $optionField->fieldType?->name,
            ],
            'has_category' => (bool) $optionField->has_category,
            'is_input' => (bool) $optionField->is_input,
            'payload_section_id' => (int) $optionField->payload_section_id,
            'parent_option_id' => $optionField->option_field_id,
        ]);
    }



    /** Decode a booking value from JSON when possible. @param mixed $value @return mixed */
    private function decodeBookingValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE
            ? $decoded
            : $value;
    }

    /** Get the field type [id, name]; room time multi-selects are reported as TIME. @param Booking $booking, mixed $formField @return ?array */
    private function retreiveFieldTypeObject(Booking $booking, mixed $formField): ?array
    {
        $formFieldIdByBookingType = match ((int) $booking->booking_type_id) {
            BookingTypeEnum::LECTURE_ROOM_BOOKING->getBookingTypeId() => FormFieldEnum::LECTURE_ROOM_TIME->getFormFieldId(),
            BookingTypeEnum::SMALL_COMMAND_ROOM_BOOKING->getBookingTypeId() => FormFieldEnum::SMALL_COMMAND_ROOM_TIME->getFormFieldId(),
            BookingTypeEnum::TECH_HAMAMA_FIRST_BOOKING->getBookingTypeId() => FormFieldEnum::TECH_HAMAMA_FIRST_TIME->getFormFieldId(),
            BookingTypeEnum::TECH_HAMAMA_SECOND_BOOKING->getBookingTypeId() => FormFieldEnum::TECH_HAMAMA_SECOND_TIME->getFormFieldId(),
            default => null,
        };

        if (
            $formFieldIdByBookingType &&
            $formField->id == $formFieldIdByBookingType &&
            (int) $formField->field_type_id === FieldTypeEnum::MULTI_SELECT->value
        ) {
            return [
                'id' => FieldTypeEnum::TIME->value,
                'name' => 'time',
            ];
        }

        return [
            'id' => $formField?->fieldType?->id,
            'name' => $formField?->fieldType?->name,
        ];
    }

    /** Remove null values from an array. @param array $data @return array */
    private function removeNullValues(array $data): array
    {
        return array_filter($data, fn($value) => !is_null($value));
    }

}

<?php

namespace App\Services;


use App\Domain\Config\FormFieldRules;
use App\Domain\Workflow\Workflow;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormFieldEnum;
use App\Models\Booking;
use App\Models\File;
use App\Models\FormField;
use App\Models\Image;
use App\Models\OptionField;
use App\Enums\StatusBookingEnum;
use App\Http\Resources\FormFieldResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BookingValueFormatterService
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly AttachmentService $attachmentService,
    ) {
    }

    /** Formats a booking's values and uploads for display, merging nested form field trees. @return ?array */
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

    /** Appends one entry per upload field holding files/images; untagged uploads go to the root upload fields. */
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

    private function formatFilesPayload(mixed $files): array
    {
        return $files
            ->map(fn(File $file) => $this->removeNullValues([
                'id' => $file->id,
                'type' => 'file',
                'name' => $file->file_name,
                'display_name' => $file->display_name,
                // A file named in the json whose bytes have not arrived has no url yet (resolve() also tries the extension).
                'url' => ($path = $this->attachmentService->resolve($file)) ? $this->storageService->generateFileTemporaryUrl($path) : null,
                'size' => $file->size ?? null,
            ]))
            ->values()
            ->all();
    }

    private function formatImagesPayload(mixed $images): array
    {
        return $images
            ->map(fn(Image $image) => $this->removeNullValues([
                'id' => $image->id,
                'type' => 'image',
                'name' => $image->file_name,
                'url' => ($path = $this->attachmentService->resolve($image)) ? $this->storageService->generateImageTemporaryUrl($path) : null,
            ]))
            ->values()
            ->all();
    }

    /** Same field restrictions as the create form; null unless systemReferences is eager-loaded. */
    private function resolveFieldRestrictions(FormField $formField): ?array
    {
        if (!$formField->relationLoaded('systemReferences')) {
            return null;
        }

        return (new FormFieldResource($formField))->resolveFieldRestrictions();
    }

    /** Whether the current user may correct this field's value at the booking's status. */
    private function isEditable(Booking $booking, mixed $formField): bool
    {
        $user = Auth::user();

        if (!$user || !$formField) {
            return false;
        }

        // Only a date and a time can be corrected.
        if (!(bool) $formField->editable || !in_array((int) $formField->field_type_id, FormFieldRules::EDITABLE_TYPES, true)) {
            return false;
        }

        $status = StatusBookingEnum::tryFrom((int) $booking->status_id);

        if ($status === null) {
            return false;
        }

        // Same rule as on submit (Workflow::canEditValues): whoever's turn it is, until the final approval.
        // Uses the user's own permission names: hasPermissionTo() throws for a permission that does not exist.
        return app(Workflow::class)->canEditValues(
            $status,
            $user->workflowPermissionNames(),
            (int) $booking->booking_type_id,
        );
    }

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

    /** A tree node has uuid + field_name and no field_uuid. */
    private function isFormFieldTreeNode(array $item): bool
    {
        return isset($item['uuid'])
            && isset($item['field_name'])
            && !isset($item['field_uuid']);
    }

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

    /** Merges option_fields, value and child form_fields recursively. */
    private function mergeFormFieldNode(array $existing, array $incoming): array
    {
        if (isset($incoming['option_fields'])) {
            $existing['option_fields'] = $incoming['option_fields'];
        }

        if (array_key_exists('value', $incoming)) {
            $existing['value'] = $incoming['value'];
        }

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

    /** Formats option-based values; depth comes from option_field_id, not the booking type. */
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

    /** Adds an option path to the list, joining it with the same option when already there. */
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

    /** option_fields holds one option node or a list; always returns a list. */
    private function normalizeOptionFieldNodes(mixed $optionFields): array
    {
        if (!is_array($optionFields) || empty($optionFields)) {
            return [];
        }

        return array_is_list($optionFields)
            ? array_values($optionFields)
            : [$optionFields];
    }

    /** One choice under a single-value field type is an object at each level, else a list. */
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

    private function isMultiValueFieldTypeId(?int $fieldTypeId): bool
    {
        return in_array($fieldTypeId, [
            FieldTypeEnum::CHECKBOX->value,
            FieldTypeEnum::MULTI_SELECT->value,
        ], true);
    }

    /** An option opens another level when flagged or typed as a category. */
    private function isCategoryOptionField(?OptionField $optionField): bool
    {
        return $optionField
            && (
                (bool) $optionField->has_category
                || $this->isCategoryFieldTypeId((int) $optionField->field_type_id)
            );
    }

    private function isCategoryFormField(?FormField $formField): bool
    {
        return $formField
            && $this->isCategoryFieldTypeId((int) $formField->field_type_id);
    }
    /** Returns the form field tree when needed, else the regular flat payload. */
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


    /** Builds the nested path recursively; the leaf gets the value or option_fields. */
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

    /** Ancestor chain of a selected option, from its root category down to itself. */
    private function buildOptionFieldPath(?OptionField $optionField, mixed $value = null): ?array
    {
        if (!$optionField) {
            return null;
        }

        $node = $this->formatOptionFieldNode($optionField);

        if (!is_null($value) && !$this->isCategoryOptionField($optionField)) {
            // A free-text option holds what the user typed, shown by its field type like a plain input.
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

    /** Field type [id, name]; room time multi-selects are reported as TIME. */
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

    private function removeNullValues(array $data): array
    {
        return array_filter($data, fn($value) => !is_null($value));
    }

}

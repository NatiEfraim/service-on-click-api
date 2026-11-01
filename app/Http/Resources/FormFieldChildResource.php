<?php

namespace App\Http\Resources;

use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormFieldChildResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource instanceof FormField
            ? $this->formFieldChild($this->resource)
            : $this->optionFieldChild($this->resource);
    }

    private function formFieldChild(FormField $formField): array
    {
        return array_filter([
            'type' => 'form_field',
            'id' => $formField->id,
            'uuid' => $formField->uuid,
            'name' => $formField->field_name,
            'display_name' => $formField->field_display_name,
            'is_required' => (bool) $formField->is_required,
            'is_input' => (bool) $formField->is_input,
            'has_category' => false,
            'payload_section_id' => $formField->payload_section_id,
            'field_type' => [
                'id' => $formField->fieldType?->id,
                'name' => $formField->fieldType?->name,
            ],
            'form_field_id' => $formField->form_field_id,
        ], fn($value) => !is_null($value));
    }

    private function optionFieldChild(OptionField $optionField): array
    {
        return array_filter([
            'type' => 'option_field',
            'id' => $optionField->id,
            'uuid' => $optionField->uuid,
            'name' => $optionField->name,
            'display_name' => $optionField->name,
            'is_required' => null,
            'is_input' => (bool) $optionField->is_input,
            'has_category' => (bool) $optionField->has_category,
            'category_name' => $optionField->category_name,
            'payload_section_id' => $optionField->payload_section_id,
            'field_type' => [
                'id' => $optionField->fieldType?->id,
                'name' => $optionField->fieldType?->name,
            ],
            'form_field_id' => $optionField->form_field_id,
            'parent_option_id' => $optionField->option_field_id,
        ], fn($value) => !is_null($value));
    }
}

<?php

namespace App\Http\Resources;

use App\Enums\FormFieldEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SelectOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->buildOptionFieldTree($this->resource);
    }

    private function buildOptionFieldTree($option): array
    {
        $optionData = [
            'option_id' => $option->id,
            'option_name' => $option->name,
            'field_type' => $option->fieldType?->name,
            'has_category' => (bool) $option->has_category,
        ];

        if (!is_null($option->option_field_id)) {
            $optionData['parent_option_id'] = $option->option_field_id;
        }

        if ($option->category_name) {
            $optionData['category_name'] = $option->category_name;
        }

        if ($option->has_category && $option->childOptionField->isNotEmpty()) {
            $headlineName = FormFieldEnum::getSettingsDisplayNameByFormFieldId(
                formFieldId: (int) $option->form_field_id
            );

            if ($headlineName) {
                $optionData['headline_name'] = $headlineName;
            }

            $optionData['option_fields'] = $option->childOptionField
                ->map(function ($childOption) {
                    return $this->buildOptionFieldTree($childOption);
                })
                ->filter()
                ->values()
                ->all();
        }

        return $this->removeNullValues($optionData);
    }

    private function removeNullValues(array $data): array
    {
        return array_filter($data, fn($value) => !is_null($value));
    }
}

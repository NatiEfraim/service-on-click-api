<?php

namespace App\Http\Resources;

use App\Models\FormField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight form-field resource for the requester portal.
 * Returns only the data the submission form needs; no system-reference restrictions.
 */
class RequesterFormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'field_name'         => $this->field_name,
            'field_display_name' => $this->field_display_name,
            'is_required'        => (bool) $this->is_required,
            'position'           => (int) $this->position,
            'field_type'         => [
                'id'   => $this->fieldType?->id,
                'name' => $this->fieldType?->name,
            ],
            'validations'        => $this->field_validations ?? null,
            'options'            => $this->buildOptions(),
            'children'           => $this->buildChildren(),
        ];
    }

    private function buildOptions(): ?array
    {
        if (!$this->relationLoaded('optionField') || $this->optionField->isEmpty()) {
            return null;
        }

        return $this->optionField
            ->whereNull('option_field_id')
            ->where('is_active', true)
            ->sortBy('position')
            ->map(fn ($opt) => [
                'id'   => $opt->id,
                'name' => $opt->name,
            ])
            ->values()
            ->all();
    }

    private function buildChildren(): ?array
    {
        if (!$this->relationLoaded('childFormFields') || $this->childFormFields->isEmpty()) {
            return null;
        }

        return $this->childFormFields
            ->map(fn (FormField $child) => [
                'id'                 => $child->id,
                'field_name'         => $child->field_name,
                'field_display_name' => $child->field_display_name,
                'is_required'        => (bool) $child->is_required,
                'position'           => (int) $child->position,
                'field_type'         => [
                    'id'   => $child->fieldType?->id,
                    'name' => $child->fieldType?->name,
                ],
                'validations'  => $child->field_validations ?? null,
                'options'      => $child->relationLoaded('optionField') && $child->optionField->isNotEmpty()
                    ? $child->optionField
                        ->whereNull('option_field_id')
                        ->where('is_active', true)
                        ->sortBy('position')
                        ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])
                        ->values()
                        ->all()
                    : null,
            ])
            ->values()
            ->all();
    }
}

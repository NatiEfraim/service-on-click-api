<?php

namespace App\Http\Resources;

use App\Models\FormField;
use Illuminate\Support\Collection;
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
            // Where the field sits (requester / handler), its width on a 4-column grid, and the person lookup it triggers / is filled from.
            'section'            => $this->sectionEnum()->value,
            'col_span'           => (int) ($this->col_span ?? 1),
            'lookup_source'      => $this->lookup_source,
            'autofill_key'       => $this->autofill_key,
            'field_type'         => [
                'id'   => $this->fieldType?->id,
                'name' => $this->fieldType?->name,
            ],
            // Always an object ("rule" => Hebrew message) or null, whatever shape the column holds.
            'validations'        => $this->normalizedValidations(),
            'options'            => $this->buildOptions(),
            'children'           => $this->buildChildren(),
        ];
    }

    private function buildOptions(): ?array
    {
        if (!$this->relationLoaded('optionField') || $this->optionField->isEmpty()) {
            return null;
        }

        return self::optionTree($this->optionField);
    }

    /**
     * Whether choosing this option asks the requester to type a value: marked "type in a value" (is_input), or of a
     * value type - anything but closed text or a list (FormFieldRules::defaultsFor's rule), e.g. a time, a number, a date.
     */
    public static function asksForValue($option): bool
    {
        if ($option->is_input) {
            return true;
        }
        $typeId = (int) ($option->field_type_id ?? 0);

        return $typeId > 0
            && $typeId !== \App\Enums\FieldTypeEnum::TEXT->value
            && !in_array($typeId, \App\Domain\Config\FormFieldRules::OPTION_TYPES, true);
    }

    /**
     * The active choices of a field as a tree, from the field's flat list of options (already loaded, so no queries):
     * each choice says whether the requester types a value with it (is_input, and the value's field_type), whether it
     * is a list with sub-choices (has_category), carries its stable code when it has one, and its sub-choices as children.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function optionTree(Collection $options, ?int $parentId = null): array
    {
        return $options
            ->where('is_active', true)
            ->filter(fn ($opt) => ($opt->option_field_id === null ? null : (int) $opt->option_field_id) === $parentId)
            ->sortBy('id')
            ->map(function ($opt) use ($options) {
                $children = self::optionTree($options, (int) $opt->id);

                return [
                    'id'           => $opt->id,
                    'name'         => $opt->name,
                    'code'         => $opt->code,
                    'is_input'     => self::asksForValue($opt),
                    'has_category' => (bool) $opt->has_category,
                    'field_type'   => $opt->field_type_id ? [
                        'id'   => (int) $opt->field_type_id,
                        'name' => $opt->relationLoaded('fieldType') ? $opt->fieldType?->name : null,
                    ] : null,
                    'children'     => $children ?: null,
                ];
            })
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
                'section'            => $child->sectionEnum()->value,
                'col_span'           => (int) ($child->col_span ?? 1),
                'lookup_source'      => $child->lookup_source,
                'autofill_key'       => $child->autofill_key,
                'field_type'         => [
                    'id'   => $child->fieldType?->id,
                    'name' => $child->fieldType?->name,
                ],
                'validations'  => $child->normalizedValidations(),
                'options'      => $child->relationLoaded('optionField') && $child->optionField->isNotEmpty()
                    ? self::optionTree($child->optionField)
                    : null,
            ])
            ->values()
            ->all();
    }
}

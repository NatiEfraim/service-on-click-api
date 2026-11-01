<?php

namespace App\Http\Resources;

use App\Enums\FieldTypeEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\BookingTypeEnum;
use App\Models\FormField;

class IndexSelectsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'details' => $this->formatFormFields(
                formFields: $this->resource['fields']
            ),

            'booking_type' => $this->formatBookingType(
                bookingType: $this->resource['booking_type']
            ),
        ];
    }

    private function formatFormFields(mixed $formFields): array
    {
        return $formFields
            ->map(fn($formField) => $this->formatFormField($formField))
            ->filter()
            ->values()
            ->all();
    }

    private function formatFormField(FormField $formField): array
    {
        $childFormFields = $formField->childFormFields
            ->filter(fn($childFormField) => in_array(
                (int) $childFormField->field_type_id,
                FieldTypeEnum::selectedFieldTypeIds(),
                true
            ))
            ->values();

        return $this->removeNullValues([
            'id' => $formField->id,
            'form_field_name' => $formField->field_display_name,
            'form_field_type' => $formField->fieldType?->name,
            'has_category' => $this->hasCategory($formField),

            'form_fields' => $childFormFields->isNotEmpty()
                ? $this->formatFormFields($childFormFields)
                : null,
        ]);
    }

    private function formatBookingType(mixed $bookingType): array
    {
        return [
            'id' => $bookingType->id,
            'name' => BookingTypeEnum::formatBookingTypeRouteName($bookingType->name),
            'display_name' => $bookingType->display_name,
            'icon' => $bookingType->icon,
        ];
    }

    private function hasCategory(FormField $formField): bool
    {
        return (bool) ($formField->optionField->first()?->has_category ?? false);
    }

    private function removeNullValues(array $data): array
    {
        return array_filter($data, fn($value) => !is_null($value));
    }
}

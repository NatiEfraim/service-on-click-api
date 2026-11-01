<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\BookingTypeEnum;
use App\Enums\SystemReferenceEnum;
use App\Models\SystemReference;


class GetSystemReferencesResource extends JsonResource
{
    /** @var array<int> Booking type ids whose restriction_dates resolve to the live BookingRestrictionDate list. */
    private array $restrictionDatesBookingTypeIds;

    /** @var array<int> The RESTRICTION_DATES system reference id for each booking type above, same order. */
    private array $restrictionDatesSystemReferenceIds;

    public function __construct($resource)
    {
        parent::__construct($resource);

        $this->restrictionDatesBookingTypeIds = [
            BookingTypeEnum::CAR_BOOKING->getBookingTypeId(),
            BookingTypeEnum::BARBER_BOOKING->getBookingTypeId(),
            BookingTypeEnum::OUT_UNIT_FOOD_BOOKING->getBookingTypeId(),
            BookingTypeEnum::IN_UNIT_FOOD_BOOKING->getBookingTypeId(),
        ];

        $this->restrictionDatesSystemReferenceIds = [
            SystemReferenceEnum::CAR_RESTRICTION_DATES->getSystemReferenceId(),
            SystemReferenceEnum::BARBER_RESTRICTION_DATES->getSystemReferenceId(),
            SystemReferenceEnum::OUT_UNIT_RESTRICTION_DATES->getSystemReferenceId(),
            SystemReferenceEnum::IN_UNIT_RESTRICTION_DATES->getSystemReferenceId(),
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'booking_type_id' => $this->resource['booking_type_id'],
            'system_refences' => $this->formatSystemReferences(
                systemReferences: $this->resource['system_references'],
                bookingTypeId: (int) $this->resource['booking_type_id']
            ),
            'statement' => $this->resource['statement'],
        ];
    }

    private function formatSystemReferences(
        mixed $systemReferences,
        int $bookingTypeId
    ): array {
        return $systemReferences
            ->map(function (SystemReference $systemReference) use ($bookingTypeId) {
                return $this->formatSystemReference(
                    systemReference: $systemReference,
                    bookingTypeId: $bookingTypeId
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function formatSystemReference(
        SystemReference $systemReference,
        int $bookingTypeId
    ): array {
        $detailsValues = $systemReference->decodedValue();

        return [
            'id' => $systemReference->id,
            'title' => $systemReference->title,
            'description' => $systemReference->description,
            'fieldType' => [
                'id' => $systemReference->fieldType?->id,
                'name' => $systemReference->fieldType?->name,
            ],
            'details' => $this->formatDetails(
                detailsValues: $detailsValues,
                systemReference: $systemReference,
                bookingTypeId: $bookingTypeId
            ),
        ];
    }

    private function formatDetails(
        array $detailsValues,
        SystemReference $systemReference,
        int $bookingTypeId
    ): array {
        return collect(array_keys($detailsValues))
            ->map(function (string $key) use ($detailsValues, $systemReference, $bookingTypeId) {
                return [
                    'key' => $key,
                    'value' => $this->resolveValue(
                        key: $key,
                        detailsValues: $detailsValues,
                        systemReference: $systemReference,
                        bookingTypeId: $bookingTypeId
                    ),
                    'label' => $this->getLabelForKey($key),
                ];
            })
            ->values()
            ->all();
    }

    /** Restriction dates come from the booking type. */
    private function resolveValue(
        string $key,
        array $detailsValues,
        SystemReference $systemReference,
        int $bookingTypeId
    ): mixed {
        $value = $detailsValues[$key] ?? '';

        if (
            in_array($bookingTypeId, $this->restrictionDatesBookingTypeIds, true)
            && in_array((int) $systemReference->id, $this->restrictionDatesSystemReferenceIds, true)
        ) {
            return SystemReferenceEnum::getBookingRestrictionDates($bookingTypeId);
        }

        return $value;
    }

    /** Hebrew labels for system_reference keys. */
    private function getLabelForKey(string $key): string
    {
        return match ($key) {
            'stock_cars' => 'סה״כ כמות רכבים',
            'start_time' => 'שעת התחלה',
            'end_time' => 'שעת סיום',
            'contact_phone' => 'מספר טלפון',
            'bookings_limit_time' => 'מספר שעות',
            'limit_images' => 'מספר תמונות',
            'haircut_time' => 'זמן תספורת',
            'advance_month' => 'מספר חודשים',
            'dates' => 'תאריכים',
            'duration_time' => 'זמן שהייה במתחם',
            'max' => 'כמות מקסימלית',
            'min' => 'כמות מינימלית',
            'multiple_of' => 'כפולות כמות אנשים',
            'drivers_amount' => 'כמות נהגים',
            'clean_time' => 'זמן ניקוי וסידור המספרה',
            'count_businsday' => 'ימי עסקים',
            'cancellation_advance_days' => 'מספר ימים שאפשר לבטל מראש',
            'minimum_advance_days' => 'מינימום ימי הזמנה מראש',
            'max_advance_days' => 'מקסימום ימים להזמנה מראש',
            default => $key,
        };
    }
}

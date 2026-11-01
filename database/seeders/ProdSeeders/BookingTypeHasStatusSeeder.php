<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\StatusBookingEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasStatus;
use Illuminate\Database\Seeder;

class BookingTypeHasStatusSeeder extends Seeder
{
    /** Statuses common to every booking type (no PROCESSING; vice approver uses VICE_APPROVER_*). @var array<int> */
    private array $commonStatusIds = [
        StatusBookingEnum::RECEIVED->value,
        StatusBookingEnum::VICE_APPROVER_APPROVED->value,
        StatusBookingEnum::VICE_APPROVER_REJECTED->value,
        StatusBookingEnum::APPROVED->value,
        StatusBookingEnum::REJECTED->value,
        StatusBookingEnum::COMPLETED->value,
        StatusBookingEnum::CANCELED_BY_USER->value,
        StatusBookingEnum::CANCELED_BY_APPROVER->value,
    ];

    public function run(): void
    {
        $bookingTypes = BookingType::query()->get(['id']);

        foreach ($bookingTypes as $bookingType) {
            $statusIds = [
                ...$this->commonStatusIds,
                ...$this->extraStatusIdsByBookingType((int) $bookingType->id),
            ];

            foreach (array_unique($statusIds) as $statusId) {
                BookingTypeHasStatus::query()->updateOrCreate([
                    'booking_type_id' => $bookingType->id,
                    'status_id' => $statusId,
                ]);
            }
        }
    }

    /** @return array<int> Extra statuses on top of $commonStatusIds, only relevant to specific booking types. */
    private function extraStatusIdsByBookingType(int $bookingTypeId): array
    {
        return match ($bookingTypeId) {
            BookingTypeEnum::BARBER_BOOKING->getBookingTypeId() => [
                StatusBookingEnum::APPROVED_BY_BARBER->value,
                StatusBookingEnum::CANCELED_BY_BARBER->value,
                StatusBookingEnum::CANCELED_BY_CHANGING_TIME->value,
            ],

            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId() => [
                StatusBookingEnum::PENDING_FILE->value,
                StatusBookingEnum::PENDING_PROCESSING->value,
                StatusBookingEnum::PENDING_APPROVED->value,
                StatusBookingEnum::CONTROL_OFFICER_APPROVED->value,
                StatusBookingEnum::CONTROL_OFFICER_REJECTED->value,
            ],

            default => [],
        };
    }
}

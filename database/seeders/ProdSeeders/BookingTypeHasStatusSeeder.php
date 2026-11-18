<?php

namespace Database\Seeders\ProdSeeders;

use App\Domain\Workflow\Routes\RouteRegistry;
use App\Enums\BookingTypeEnum;
use App\Enums\StatusBookingEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasStatus;
use Illuminate\Database\Seeder;

class BookingTypeHasStatusSeeder extends Seeder
{
    /** Statuses common to every booking type but the barber's (no PROCESSING; vice approver uses VICE_APPROVER_*). @var array<int> */
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

    /**
     * BARBER_BOOKING has a route of its own, with one step: the barber approves (11) or rejects (12) a received booking. 13 is set by the system when
     * the barber's schedule changes, and the requester may cancel (6). No vice approver or approver statuses.
     * @var array<int>
     */
    private array $barberStatusIds = [
        StatusBookingEnum::RECEIVED->value,
        StatusBookingEnum::CANCELED_BY_USER->value,
        StatusBookingEnum::APPROVED_BY_BARBER->value,
        StatusBookingEnum::CANCELED_BY_BARBER->value,
        StatusBookingEnum::CANCELED_BY_CHANGING_TIME->value,
    ];

    public function run(): void
    {
        $bookingTypes = BookingType::query()->get(['id']);

        foreach ($bookingTypes as $bookingType) {
            $statusIds = array_values(array_unique($this->statusIdsByBookingType((int) $bookingType->id)));

            foreach ($statusIds as $statusId) {
                BookingTypeHasStatus::query()->updateOrCreate([
                    'booking_type_id' => $bookingType->id,
                    'status_id' => $statusId,
                ]);
            }

            // On a database seeded before the route of a type changed, drop the statuses the type no longer has.
            BookingTypeHasStatus::query()
                ->where('booking_type_id', $bookingType->id)
                ->whereNotIn('status_id', $statusIds)
                ->delete();
        }
    }

    /** @return array<int> Every status the booking type can reach. */
    private function statusIdsByBookingType(int $bookingTypeId): array
    {
        // A type with a route of its own (the clearance grant) reaches the route's statuses only.
        if (($route = RouteRegistry::byBookingTypeId()[$bookingTypeId] ?? null) !== null) {
            return $route->statusIds();
        }

        if ($bookingTypeId === BookingTypeEnum::BARBER_BOOKING->getBookingTypeId()) {
            return $this->barberStatusIds;
        }

        return [
            ...$this->commonStatusIds,
            ...$this->extraStatusIdsByBookingType($bookingTypeId),
        ];
    }

    /** @return array<int> Extra statuses on top of $commonStatusIds, only relevant to specific booking types. */
    private function extraStatusIdsByBookingType(int $bookingTypeId): array
    {
        return match ($bookingTypeId) {
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

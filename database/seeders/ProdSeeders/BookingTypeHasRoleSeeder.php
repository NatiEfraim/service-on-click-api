<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use Illuminate\Database\Seeder;

class BookingTypeHasRoleSeeder extends Seeder
{
    /** Roles on every booking type; each gets a "{role}_{booking_type_id}" permission. @var array<int> */
    private array $commonRoleIds = [
        RoleEnum::ADMIN->value,
        RoleEnum::APPROVER->value,
        RoleEnum::VICE_APPROVER->value,
        RoleEnum::USER->value,
    ];

    public function run(): void
    {
        $bookingTypes = BookingType::query()->get(['id']);

        foreach ($bookingTypes as $bookingType) {
            $roleIds = [
                ...$this->commonRoleIds,
                ...$this->extraRoleIdsByBookingType((int) $bookingType->id),
            ];

            foreach ($roleIds as $roleId) {
                BookingTypeHasRole::query()->updateOrCreate([
                    'booking_type_id' => $bookingType->id,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    /** @return array<int> Roles on top of $commonRoleIds, only for specific booking types. */
    private function extraRoleIdsByBookingType(int $bookingTypeId): array
    {
        return match ($bookingTypeId) {
            // Its route starts at the control officer: Workflow::hasControlOfficerStage reads this link.
            BookingTypeEnum::TRANSPORTATION_BOOKING->getBookingTypeId() => [RoleEnum::TRANSPORTATION_CONTROL_OFFICER->value],

            default => [],
        };
    }
}

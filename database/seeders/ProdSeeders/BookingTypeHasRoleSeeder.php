<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\RoleEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use Illuminate\Database\Seeder;

class BookingTypeHasRoleSeeder extends Seeder
{
    /** Roles on every booking type but the barber's; each gets a "{role}_{booking_type_id}" permission. @var array<int> */
    private array $commonRoleIds = [
        RoleEnum::ADMIN->value,
        RoleEnum::APPROVER->value,
        RoleEnum::VICE_APPROVER->value,
        RoleEnum::USER->value,
    ];

    /**
     * BARBER_BOOKING has one step - the barber answers a received booking (approve 11, reject 12) - and the one who answers is its APPROVER:
     * approver_5. The admin of the type holds that turn too, as on every type. There is no vice approver: nobody has a step before the approver's, so
     * no vice_approver_5 permission is created, the roles screens offer only the approver flag for this type and Workflow gives that role the buttons.
     * @var array<int>
     */
    private array $barberRoleIds = [
        RoleEnum::ADMIN->value,
        RoleEnum::APPROVER->value,
        RoleEnum::USER->value,
    ];

    public function run(): void
    {
        $bookingTypes = BookingType::query()->get(['id']);

        foreach ($bookingTypes as $bookingType) {
            $roleIds = array_values(array_unique($this->roleIdsByBookingType((int) $bookingType->id)));

            foreach ($roleIds as $roleId) {
                BookingTypeHasRole::query()->updateOrCreate([
                    'booking_type_id' => $bookingType->id,
                    'role_id' => $roleId,
                ]);
            }

            // On a database seeded before the roles of a type changed, drop the roles the type no longer has (a permission already created for one
            // stays until the database is rebuilt: nobody is revoked here).
            BookingTypeHasRole::query()
                ->where('booking_type_id', $bookingType->id)
                ->whereNotIn('role_id', $roleIds)
                ->delete();
        }
    }

    /** @return array<int> Every role the booking type has. */
    private function roleIdsByBookingType(int $bookingTypeId): array
    {
        if ($bookingTypeId === BookingTypeEnum::BARBER_BOOKING->getBookingTypeId()) {
            return $this->barberRoleIds;
        }

        return [
            ...$this->commonRoleIds,
            ...$this->extraRoleIdsByBookingType($bookingTypeId),
        ];
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

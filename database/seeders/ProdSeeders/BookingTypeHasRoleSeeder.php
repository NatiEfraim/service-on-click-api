<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\RoleEnum;
use App\Models\BookingType;
use App\Models\BookingTypeHasRole;
use Illuminate\Database\Seeder;

class BookingTypeHasRoleSeeder extends Seeder
{
    /** Roles relevant to every booking type; each of them gets a "{role}_{booking_type_id}" permission. @var array<int> */
    private array $commonRoleIds = [
        RoleEnum::ADMIN->value,
        RoleEnum::APPROVER->value,
        RoleEnum::VICE_APPROVER->value,
    ];

    /** Run the database seeds. */
    public function run(): void
    {
        $bookingTypes = BookingType::query()->get(['id']);

        foreach ($bookingTypes as $bookingType) {
            foreach ($this->commonRoleIds as $roleId) {
                BookingTypeHasRole::query()->updateOrCreate([
                    'booking_type_id' => $bookingType->id,
                    'role_id' => $roleId,
                ]);
            }
        }
    }
}

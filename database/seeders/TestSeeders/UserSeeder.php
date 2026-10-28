<?php

namespace Database\Seeders\TestSeeders;

use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Synthetic users for local development: one manager per role (log in with the personal number) and a few people who place bookings. */
class UserSeeder extends Seeder
{
    /** Run the database seeds. */
    public function run(): void
    {
        $managers = [
            // personal_number => [full_name, role]
            '99804761' => ['רועי סחייק', RoleEnum::SUPER_ADMIN],
            '99810738' => ['נתנאל רפאל אפרים', RoleEnum::ADMIN],
            '99815080' => ['ליאון לב', RoleEnum::ADMIN],
            '99815386' => ['דניאל יצחק קם', RoleEnum::APPROVER],
            '99807387' => ['אופיר גולדברג', RoleEnum::USER],
        ];

        $bookingTypeIds = BookingType::query()->pluck('id');

        foreach ($managers as $personalNumber => [$fullName, $roleEnum]) {
            $user = User::updateOrCreate(
                ['personal_number' => $personalNumber],
                [
                    'full_name' => $fullName,
                    'unit_id' => UnitEnum::H->value,
                    'is_deleted' => false,
                ]
            );

            $user->assignRole($roleEnum->value);

            // The super admin manages the system as a whole; every other role works per booking type.
            if ($roleEnum !== RoleEnum::SUPER_ADMIN) {
                $user->givePermissionTo(
                    $bookingTypeIds->map(fn(int $id): string => $roleEnum->toPermissionEnum()->keyForId($id))->all()
                );
            }
        }

        // A person with no role: can log in, but is allowed nothing.
        User::updateOrCreate(
            ['personal_number' => '1000005'],
            ['full_name' => 'משתמש ללא הרשאות בדיקה', 'unit_id' => UnitEnum::H->value, 'is_deleted' => false]
        );

        // People who place bookings in the mobile app - identified there by their ID number.
        foreach (range(1, 3) as $number) {
            User::updateOrCreate(
                ['personal_id' => '90000000' . $number],
                [
                    'full_name' => 'מזמין בדיקה ' . $number,
                    'phone' => '050000000' . $number,
                    'unit_id' => UnitEnum::H->value,
                    'is_deleted' => false,
                ]
            );
        }
    }
}

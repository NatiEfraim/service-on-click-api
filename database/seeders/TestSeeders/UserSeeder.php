<?php

namespace Database\Seeders\TestSeeders;

use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Users for local development: the team (log in with the personal number) and a few people who place bookings. */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $managers = [
            // full_name, personal_id (ID number), phone, personal_number (ADFS login), role
            ['full_name' => 'רועי סחייק', 'personal_id' => '206094468', 'phone' => '0545452493', 'personal_number' => '99804761', 'role' => RoleEnum::SUPER_ADMIN],
            ['full_name' => 'נתנאל רפאל אפרים', 'personal_id' => '315552810', 'phone' => '0532157802', 'personal_number' => '99810738', 'role' => RoleEnum::ADMIN],
            ['full_name' => 'ליאון לב', 'personal_id' => '325639797', 'phone' => '0547453538', 'personal_number' => '99815080', 'role' => RoleEnum::ADMIN],
            ['full_name' => 'דניאל יצחק קם', 'personal_id' => '214440067', 'phone' => '0535215312', 'personal_number' => '99815386', 'role' => RoleEnum::APPROVER],
            ['full_name' => 'אופיר גולדברג', 'personal_id' => '99801758', 'phone' => '0527576444', 'personal_number' => '99807387', 'role' => RoleEnum::USER],
            ['full_name' => 'משה כרמל', 'personal_id' => '057723439', 'phone' => '0529272108', 'personal_number' => '99802210', 'role' => RoleEnum::ADMIN],
            ['full_name' => 'לירן אסלן', 'personal_id' => '038156246', 'phone' => '0524322023', 'personal_number' => '99801758', 'role' => RoleEnum::ADMIN],
        ];

        $bookingTypeIds = BookingType::query()->pluck('id');

        foreach ($managers as $manager) {
            $roleEnum = $manager['role'];

            $user = User::updateOrCreate(
                ['personal_number' => $manager['personal_number']],
                [
                    'full_name' => $manager['full_name'],
                    'personal_id' => $manager['personal_id'],
                    'phone' => $manager['phone'],
                    'unit_id' => UnitEnum::H->value,
                    'is_deleted' => false,
                ]
            );

            $user->assignRole($roleEnum->value);

            // Super admin manages the whole system; other roles work per booking type.
            if ($roleEnum !== RoleEnum::SUPER_ADMIN) {
                $user->givePermissionTo(
                    $bookingTypeIds->map(fn(int $id): string => $roleEnum->toPermissionEnum()->keyForId($id))->all()
                );
            }
        }

    }
}

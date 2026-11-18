<?php

namespace Database\Seeders;

use Database\Seeders\ProdSeeders\ActionTypeSeeder;
use Database\Seeders\ProdSeeders\BookingTypeHasStatusSeeder;
use Database\Seeders\ProdSeeders\BookingTypeSeeder;
use Database\Seeders\ProdSeeders\IctFormFieldSeeder;
use Database\Seeders\ProdSeeders\IctOptionFieldSeeder;
use Database\Seeders\ProdSeeders\InfosecIctFormFieldSeeder;
use Database\Seeders\ProdSeeders\InfosecIctOptionFieldSeeder;
use Database\Seeders\ProdSeeders\RoleAndPermissionSeeder;
use Database\Seeders\ProdSeeders\StatusSeeder;
use Database\Seeders\ProdSeeders\UnitHasBookingTypeSeeder;
use Illuminate\Database\Seeder;

/**
 * Seeds the ביטחון מידע and תקשוב booking types end-to-end (types 14-25, their roles, statuses, fields and options).
 * Run after a fresh migration with: php artisan db:seed --class=InfosecIctSeeder --force
 * Also safe to run on top of an existing ProdSeeder database.
 */
class InfosecIctSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // 1. Register the booking types and their group_booking_types (with topic).
            BookingTypeSeeder::class,
            // 2. Register the domain roles (infosec officer, ICT handler, the clearance route's roles) and emit their permissions.
            RoleAndPermissionSeeder::class,
            // 3. Ensure all statuses and action types exist (the clearance route has its own) before linking them to booking types.
            StatusSeeder::class,
            ActionTypeSeeder::class,
            // 4. Assign the statuses to the types (the common ones, or a route's own).
            BookingTypeHasStatusSeeder::class,
            // 5. Seed form fields: types 14-19, then 20-25.
            InfosecIctFormFieldSeeder::class,
            IctFormFieldSeeder::class,
            // 6. Seed dropdown options.
            InfosecIctOptionFieldSeeder::class,
            IctOptionFieldSeeder::class,
            // 7. Link unit H to every booking type including the new ones.
            UnitHasBookingTypeSeeder::class,
        ]);
    }
}

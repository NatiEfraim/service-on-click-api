<?php

namespace Database\Seeders;

use Database\Seeders\ProdSeeders\BookingTypeHasStatusSeeder;
use Database\Seeders\ProdSeeders\BookingTypeSeeder;
use Database\Seeders\ProdSeeders\InfosecIctFormFieldSeeder;
use Database\Seeders\ProdSeeders\InfosecIctOptionFieldSeeder;
use Database\Seeders\ProdSeeders\RoleAndPermissionSeeder;
use Database\Seeders\ProdSeeders\UnitHasBookingTypeSeeder;
use Illuminate\Database\Seeder;

/**
 * Seeds the ביטחון מידע and תקשוב booking types end-to-end.
 * Run after a fresh migration with: php artisan db:seed --class=InfosecIctSeeder --force
 * Also safe to run on top of an existing ProdSeeder database.
 */
class InfosecIctSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // 1. Register the 6 booking types and 3 group_booking_types (with topic).
            BookingTypeSeeder::class,
            // 2. Register INFOSEC_OFFICER + ICT_HANDLER roles and emit their permissions.
            RoleAndPermissionSeeder::class,
            // 3. Assign the common statuses to all types (idempotent loop).
            BookingTypeHasStatusSeeder::class,
            // 4. Seed form fields.
            InfosecIctFormFieldSeeder::class,
            // 5. Seed dropdown options.
            InfosecIctOptionFieldSeeder::class,
            // 6. Link unit H to every booking type including the new ones.
            UnitHasBookingTypeSeeder::class,
        ]);
    }
}

<?php

namespace Database\Seeders;

use Database\Seeders\ProdSeeders\ActionTypeSeeder;
use Database\Seeders\ProdSeeders\BookingTypeHasStatementSeeder;
use Database\Seeders\ProdSeeders\BookingTypeHasStatusSeeder;
use Database\Seeders\ProdSeeders\BookingTypeSeeder;
use Database\Seeders\ProdSeeders\FieldTypeSeeder;
use Database\Seeders\ProdSeeders\FormFieldSeeder;
use Database\Seeders\ProdSeeders\OptionFieldSeeder;
use Database\Seeders\ProdSeeders\RoleAndPermissionSeeder;
use Database\Seeders\ProdSeeders\StatusSeeder;
use Database\Seeders\ProdSeeders\SystemReferencesSeeder;
use Database\Seeders\ProdSeeders\UnitHasBookingTypeSeeder;
use Database\Seeders\ProdSeeders\UnitSeeder;
use Illuminate\Database\Seeder;

/**
 * The structure the web system runs on: booking types, roles and permissions, units, statuses, forms and system references.
 * Safe to run again (php artisan db:seed --class=ProdSeeder --force).
 */
class ProdSeeder extends Seeder
{
    public function run(): void
    {
        // Booking types first: the enums resolve their ids from the database.
        $this->call([
            BookingTypeSeeder::class,
            RoleAndPermissionSeeder::class,
            UnitSeeder::class,
            ActionTypeSeeder::class,
            FieldTypeSeeder::class,
            StatusSeeder::class,
            BookingTypeHasStatusSeeder::class,
            FormFieldSeeder::class,
            OptionFieldSeeder::class,
            SystemReferencesSeeder::class,
            UnitHasBookingTypeSeeder::class,
            BookingTypeHasStatementSeeder::class,
        ]);
    }
}

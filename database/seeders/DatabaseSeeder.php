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
use Database\Seeders\TestSeeders\DevBookingSeeder;
use Database\Seeders\TestSeeders\UserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Seed the application's database. */
    public function run(): void
    {
        // Production seeders - the structure the web system runs on. They are safe to run again.
        // The order matters: booking types first (the enums resolve booking type ids from the database).
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

        // Testing seeders - synthetic users and bookings, only for local development and tests.
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                UserSeeder::class,
                DevBookingSeeder::class,
            ]);
        }
    }
}

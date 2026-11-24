<?php

namespace Database\Seeders;

use Database\Seeders\ProdSeeders\ActionTypeSeeder;
use Database\Seeders\ProdSeeders\BookingTypeHasStatementSeeder;
use Database\Seeders\ProdSeeders\BookingTypeHasStatusSeeder;
use Database\Seeders\ProdSeeders\BookingTypeSeeder;
use Database\Seeders\ProdSeeders\FieldTypeSeeder;
use Database\Seeders\ProdSeeders\FormFieldSeeder;
use Database\Seeders\ProdSeeders\IctFormFieldSeeder;
use Database\Seeders\ProdSeeders\IctOptionFieldSeeder;
use Database\Seeders\ProdSeeders\InfosecFormFieldSeeder;
use Database\Seeders\ProdSeeders\InfosecOptionFieldSeeder;
use Database\Seeders\ProdSeeders\OptionFieldSeeder;
use Database\Seeders\ProdSeeders\RoleAndPermissionSeeder;
use Database\Seeders\ProdSeeders\StatusSeeder;
use Database\Seeders\ProdSeeders\SystemDomainContractSeeder;
use Database\Seeders\ProdSeeders\SystemReferencesSeeder;
use Database\Seeders\ProdSeeders\UnitHasBookingTypeSeeder;
use Database\Seeders\ProdSeeders\UnitSeeder;
use Database\Seeders\ProdSeeders\ViceApproverHandlerFieldSeeder;
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
            // The mobile-owned "גורם מטפל" fields, pinned to the mobile ids (fields 106/107, options 351-358).
            ViceApproverHandlerFieldSeeder::class,
            // Each web-only system seeds inside its own id blocks (SystemDomainEnum): ביטחון מידע, then תקשוב.
            InfosecFormFieldSeeder::class,
            InfosecOptionFieldSeeder::class,
            IctFormFieldSeeder::class,
            IctOptionFieldSeeder::class,
            SystemReferencesSeeder::class,
            UnitHasBookingTypeSeeder::class,
            BookingTypeHasStatementSeeder::class,
            // Last: aborts loudly if any row broke a system's id block or the camp ids shared with the mobile app.
            SystemDomainContractSeeder::class,
        ]);
    }
}

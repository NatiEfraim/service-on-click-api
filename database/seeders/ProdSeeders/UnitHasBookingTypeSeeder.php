<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\UnitEnum;
use App\Models\BookingType;
use App\Models\UnitHasBookingType;
use Illuminate\Database\Seeder;

class UnitHasBookingTypeSeeder extends Seeder
{
    /** Run the database seeds. */
    public function run(): void
    {
        $bookingTypesIds = BookingType::all()->pluck('id');

        foreach ($bookingTypesIds as $bookingTypesId) {
            UnitHasBookingType::updateOrCreate([
                'unit_id' => UnitEnum::H->value,
                'booking_type_id' => $bookingTypesId,
            ]);
        }
    }
}

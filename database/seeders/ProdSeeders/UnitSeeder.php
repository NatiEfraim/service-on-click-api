<?php

namespace Database\Seeders\ProdSeeders;

use App\Models\Unit;

use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        // Ids are pinned to UnitEnum (H = 1, N = 2, M = 3, R = 4); other seeders rely on them.
        foreach (['H', 'N', 'M', 'R'] as $index => $name) {
            Unit::unguarded(fn() => Unit::updateOrCreate(
                ['id' => $index + 1],
                ['name' => $name]
            ));
        }
    }
}

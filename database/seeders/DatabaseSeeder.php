<?php

namespace Database\Seeders;

use Database\Seeders\TestSeeders\UserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProdSeeder::class,
            UserSeeder::class
        ]);
    }
}

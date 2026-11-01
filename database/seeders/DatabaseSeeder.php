<?php

namespace Database\Seeders;

use Database\Seeders\TestSeeders\UserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProdSeeder::class);

        // Local development and tests only.
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                UserSeeder::class,
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

// use Database\Seeders\TestSeeders\DevBookingSeeder;
use Database\Seeders\TestSeeders\UserSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The production structure - the only part that runs on the servers.
        $this->call(ProdSeeder::class);

        // Testing seeders - users and synthetic bookings, only for local development and tests.
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                UserSeeder::class,
                // DevBookingSeeder::class,
            ]);
        }
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'personal_number' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'personal_id' => null,
            'phone' => null,
            'unit_id' => null,
            'is_deleted' => false,
        ];
    }

    /** A person who places bookings in the mobile app: identified by an ID number, with a phone. */
    public function bookingOwner(): static
    {
        return $this->state(fn(array $attributes) => [
            'personal_number' => null,
            'personal_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'phone' => '05' . fake()->numerify('########'),
        ]);
    }

    /** A user who was deleted: it can no longer log in. */
    public function deleted(): static
    {
        return $this->state(fn(array $attributes) => [
            'is_deleted' => true,
        ]);
    }
}

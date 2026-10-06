<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->city().' Branch',
            'name_am' => null,
            'code' => 'BR-'.fake()->unique()->numerify('###'),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'phone' => '+2511'.fake()->numerify('########'),
            'latitude' => fake()->latitude(3.0, 15.0),
            'longitude' => fake()->longitude(33.0, 48.0),
            'geofence_radius_meters' => 100,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function headquarters(): static
    {
        return $this->state([
            'name' => 'Head Office',
            'code' => 'HQ',
        ]);
    }
}

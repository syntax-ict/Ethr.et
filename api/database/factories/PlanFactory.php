<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->randomElement(['Starter', 'Professional', 'Enterprise']),
            'slug' => Str::slug(fake()->unique()->word()),
            'price_cents' => fake()->randomElement([0, 99900, 299900, 599900]),
            'max_employees' => fake()->randomElement([10, 50, 200, 999999]),
            'max_branches' => fake()->randomElement([1, 5, 20, 999]),
            'max_devices' => fake()->randomElement([2, 10, 50, 999]),
            'features' => [],
            'is_active' => true,
        ];
    }
}

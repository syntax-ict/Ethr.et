<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CostCenter> */
class CostCenterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->randomElement([
                'Administration', 'Production', 'Research', 'Logistics',
            ]).'-'.fake()->unique()->numerify('##'),
            'code' => 'CC-'.fake()->unique()->numerify('###'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

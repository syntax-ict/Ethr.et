<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GradeSalaryStep;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GradeSalaryStep> */
class GradeSalaryStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'step' => fake()->unique()->numberBetween(1, 100),
            'salary_cents' => fake()->numberBetween(500_000, 1_500_000),
        ];
    }
}

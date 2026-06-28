<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Grade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Grade> */
class GradeFactory extends Factory
{
    public function definition(): array
    {
        $min = fake()->numberBetween(300000, 1000000);

        return [
            'public_id' => (string) Str::ulid(),
            'name' => 'Grade '.fake()->unique()->randomLetter(),
            'min_salary_cents' => $min,
            'max_salary_cents' => $min + fake()->numberBetween(200000, 2000000),
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }
}

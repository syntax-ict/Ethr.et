<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->name(),
            'name_am' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2519'.fake()->numerify('########'),
            'employee_code' => 'EMP-'.fake()->unique()->numerify('####'),
            'gender' => fake()->randomElement(['male', 'female']),
            'date_of_birth' => fake()->date('Y-m-d', '-20 years'),
            'nationality' => 'Ethiopian',
            'marital_status' => fake()->randomElement(['single', 'married']),
            'status' => EmployeeStatus::CONFIRMED,
            'hire_date' => fake()->date('Y-m-d', '-1 year'),
            'salary_cents' => fake()->numberBetween(500000, 5000000),
        ];
    }

    public function hired(): static
    {
        return $this->state(['status' => EmployeeStatus::HIRED]);
    }

    public function probation(): static
    {
        return $this->state([
            'status' => EmployeeStatus::PROBATION,
            'probation_end_date' => now()->addMonths(3)->format('Y-m-d'),
        ]);
    }
}

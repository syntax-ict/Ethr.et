<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->randomElement([
                'Human Resources', 'Finance', 'IT', 'Operations',
                'Marketing', 'Sales', 'Legal', 'Engineering',
            ]).'-'.fake()->unique()->numerify('##'),
            'name_am' => null,
            'code' => 'DEPT-'.fake()->unique()->numerify('###'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withParent(Department $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'tenant_id' => $parent->tenant_id,
        ]);
    }
}

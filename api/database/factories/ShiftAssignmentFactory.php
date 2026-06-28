<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShiftAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftAssignment> */
class ShiftAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'effective_from' => now()->startOfMonth()->format('Y-m-d'),
            'effective_to' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state([
            'effective_from' => now()->subMonths(3)->format('Y-m-d'),
            'effective_to' => now()->subDay()->format('Y-m-d'),
        ]);
    }
}

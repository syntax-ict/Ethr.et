<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmployeeLoan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EmployeeLoan> */
class EmployeeLoanFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->numberBetween(5000_00, 50000_00);

        return [
            'public_id' => (string) Str::ulid(),
            'amount_cents' => $amount,
            'remaining_cents' => $amount,
            'monthly_deduction_cents' => (int) ($amount / 12),
            'start_date' => now()->format('Y-m-d'),
            'status' => 'active',
            'reason' => fake()->sentence(),
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'remaining_cents' => 0,
            'status' => 'completed',
        ]);
    }
}

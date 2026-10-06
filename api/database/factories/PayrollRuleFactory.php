<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PayrollRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PayrollRule> */
class PayrollRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->randomElement(['Transport', 'Housing', 'Position', 'Hardship']).' Allowance',
            'type' => 'fixed',
            'category' => 'allowance',
            'formula' => ['amount_cents' => fake()->numberBetween(500_00, 3000_00)],
            'is_taxable' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function fixed(int $amountCents): static
    {
        return $this->state([
            'type' => 'fixed',
            'formula' => ['amount_cents' => $amountCents],
        ]);
    }

    public function percentage(float $percent): static
    {
        return $this->state([
            'type' => 'percentage',
            'formula' => ['percent' => $percent],
        ]);
    }

    public function nonTaxable(): static
    {
        return $this->state(['is_taxable' => false]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

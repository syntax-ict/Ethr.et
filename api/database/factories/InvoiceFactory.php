<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'subscription_id' => null,
            'amount_cents' => fake()->numberBetween(50000, 500000),
            'tax_cents' => 0,
            'total_cents' => fn (array $attrs) => $attrs['amount_cents'],
            'status' => 'draft',
            'line_items' => [['description' => 'Monthly plan', 'amount_cents' => 100000]],
            'due_date' => now()->addDays(15),
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}

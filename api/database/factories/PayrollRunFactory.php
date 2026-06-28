<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PayrollRun> */
class PayrollRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'period_label' => now()->format('F Y'),
            'period_start' => now()->startOfMonth()->format('Y-m-d'),
            'period_end' => now()->endOfMonth()->format('Y-m-d'),
            'status' => 'draft',
            'employee_count' => 0,
            'gross_total_cents' => 0,
            'net_total_cents' => 0,
            'tax_total_cents' => 0,
        ];
    }
}

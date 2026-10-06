<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PayrollEntry> */
class PayrollEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'basic_salary_cents' => 500000,
            'allowances' => [],
            'deductions' => [],
            'gross_cents' => 500000,
            'income_tax_cents' => 0,
            'employee_pension_cents' => 0,
            'employer_pension_cents' => 0,
            'other_deductions_cents' => 0,
            'net_cents' => 500000,
        ];
    }
}

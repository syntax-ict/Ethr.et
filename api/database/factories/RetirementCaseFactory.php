<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RetirementCaseStatus;
use App\Enums\RetirementType;
use App\Models\RetirementCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RetirementCase> */
class RetirementCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'retirement_type' => RetirementType::MANDATORY,
            'status' => RetirementCaseStatus::INITIATED,
            'service_years' => fake()->randomFloat(2, 5, 35),
            'eligible_retirement_date' => now()->addYears(fake()->numberBetween(0, 5))->toDateString(),
        ];
    }
}

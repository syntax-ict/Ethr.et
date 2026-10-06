<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CostSharingStatus;
use App\Models\EmployeeCostSharing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EmployeeCostSharing> */
class EmployeeCostSharingFactory extends Factory
{
    public function definition(): array
    {
        $obligation = fake()->numberBetween(20000_00, 120000_00);

        return [
            'public_id' => (string) Str::ulid(),
            'total_obligation_cents' => $obligation,
            'outstanding_cents' => $obligation,
            // 10% is the commonly-cited figure, used here only as a factory
            // default — the rate is per-agreement and the column is required.
            'deduction_rate_percent' => 10,
            'status' => CostSharingStatus::ACTIVE,
            'started_on' => now()->format('Y-m-d'),
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'outstanding_cents' => 0,
            'status' => CostSharingStatus::COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => CostSharingStatus::SUSPENDED]);
    }

    /** Nearly repaid — the state where the final-month clamp has to hold. */
    public function nearlyRepaid(int $outstandingCents = 500_00): static
    {
        return $this->state(['outstanding_cents' => $outstandingCents]);
    }
}

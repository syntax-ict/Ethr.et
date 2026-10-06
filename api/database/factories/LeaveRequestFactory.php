<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeaveStatus;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDays(7);

        return [
            'public_id' => (string) Str::ulid(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $start->copy()->addDays(2)->format('Y-m-d'),
            'days' => 3,
            'reason' => fake()->sentence(),
            'status' => LeaveStatus::PENDING,
            'approved_by' => [],
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => LeaveStatus::APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => LeaveStatus::REJECTED,
            'rejected_reason' => fake()->sentence(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => LeaveStatus::CANCELLED]);
    }
}

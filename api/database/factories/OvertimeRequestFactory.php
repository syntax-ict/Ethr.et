<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OvertimeRequestStatus;
use App\Models\OvertimeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OvertimeRequest> */
class OvertimeRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'date' => now()->addDay()->toDateString(),
            'planned_minutes' => 120,
            'reason' => fake()->sentence(),
            'status' => OvertimeRequestStatus::PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => OvertimeRequestStatus::APPROVED]);
    }
}

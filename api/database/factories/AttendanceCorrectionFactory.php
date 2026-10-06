<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CorrectionStatus;
use App\Models\AttendanceCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AttendanceCorrection> */
class AttendanceCorrectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'reason' => fake()->sentence(),
            'proposed_check_in' => now()->setTime(8, 30),
            'proposed_check_out' => now()->setTime(17, 30),
            'status' => CorrectionStatus::PENDING,
            'approval_chain' => [],
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => CorrectionStatus::APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => CorrectionStatus::REJECTED]);
    }
}

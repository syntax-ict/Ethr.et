<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShiftRotationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftRotationStep> */
class ShiftRotationStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day_offset' => 0,
            'shift_id' => null,
        ];
    }

    public function restDay(): static
    {
        return $this->state(['shift_id' => null]);
    }
}

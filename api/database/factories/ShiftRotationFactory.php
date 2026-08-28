<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShiftRotation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ShiftRotation> */
class ShiftRotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => 'Three-Week Rotation',
            'name_am' => null,
            'description' => null,
            'cycle_days' => 21,
            'is_active' => true,
        ];
    }

    /** Four days on, four days off — an 8-day cycle that never aligns to a week. */
    public function fourOnFourOff(): static
    {
        return $this->state(['name' => 'Four On Four Off', 'cycle_days' => 8]);
    }

    public function weekly(): static
    {
        return $this->state(['name' => 'Weekly Rotation', 'cycle_days' => 7]);
    }
}

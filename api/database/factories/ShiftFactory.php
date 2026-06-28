<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Shift> */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => 'Morning Shift',
            'name_am' => null,
            'start_time' => '08:30',
            'end_time' => '17:30',
            'crosses_midnight' => false,
            'grace_minutes' => 15,
            'early_departure_minutes' => 15,
            'break_minutes' => 60,
            'working_days' => '1,2,3,4,5',
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function nightShift(): static
    {
        return $this->state([
            'name' => 'Night Shift',
            'start_time' => '22:00',
            'end_time' => '06:00',
            'crosses_midnight' => true,
        ]);
    }

    public function afternoonShift(): static
    {
        return $this->state([
            'name' => 'Afternoon Shift',
            'start_time' => '14:00',
            'end_time' => '22:00',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

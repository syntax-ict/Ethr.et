<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Holiday> */
class HolidayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->randomElement(['Ethiopian New Year', 'Timkat', 'Meskel', 'Eid al-Fitr', 'Christmas']),
            'name_am' => null,
            'date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'ethiopian_calendar' => false,
            'recurring' => true,
            'is_active' => true,
        ];
    }

    public function ethiopianCalendar(): static
    {
        return $this->state(['ethiopian_calendar' => true]);
    }
}

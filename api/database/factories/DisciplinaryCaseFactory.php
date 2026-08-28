<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DisciplinaryCaseStatus;
use App\Enums\DisciplinaryCategory;
use App\Models\DisciplinaryCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DisciplinaryCase> */
class DisciplinaryCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'category' => DisciplinaryCategory::MISCONDUCT,
            'description' => 'Test case description.',
            'incident_date' => now()->subDay()->toDateString(),
            'status' => DisciplinaryCaseStatus::REPORTED,
            'investigation_notes' => [],
        ];
    }
}

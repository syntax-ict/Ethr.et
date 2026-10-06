<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProfileUpdateStatus;
use App\Models\ProfileUpdateRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProfileUpdateRequest> */
class ProfileUpdateRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'field_name' => 'name',
            'old_value' => fake()->name(),
            'new_value' => fake()->name(),
            'status' => ProfileUpdateStatus::PENDING,
        ];
    }

    public function forField(string $field): static
    {
        return $this->state(['field_name' => $field]);
    }

    public function approved(): static
    {
        return $this->state(['status' => ProfileUpdateStatus::APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ProfileUpdateStatus::REJECTED]);
    }
}

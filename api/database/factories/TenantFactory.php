<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'subdomain' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 9999),
            'status' => TenantStatus::ACTIVE,
            'default_locale' => 'en',
            'timezone' => 'Africa/Addis_Ababa',
            'ethiopian_calendar' => true,
            'trial_ends_at' => now()->addDays(30),
        ];
    }

    public function trial(): static
    {
        return $this->state([
            'status' => TenantStatus::TRIAL,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::SUSPENDED]);
    }

    public function expiredTrial(): static
    {
        return $this->state([
            'status' => TenantStatus::TRIAL,
            'trial_ends_at' => now()->subDay(),
        ]);
    }
}

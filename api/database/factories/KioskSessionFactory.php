<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KioskSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<KioskSession> */
class KioskSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->words(2, true).' Kiosk',
            'token' => KioskSession::generateToken(),
            'admin_pin' => Hash::make('1234'),
            'device_identifier' => 'device-'.fake()->unique()->numerify('###'),
            'status' => 'active',
            'activated_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => 'inactive',
            'deactivated_at' => now(),
        ]);
    }
}

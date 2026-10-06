<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->randomElement(['Main Entrance', 'Back Door', 'Floor 2', 'Reception']).' Terminal',
            'serial_number' => 'SN-'.fake()->unique()->numerify('########'),
            'adapter_type' => fake()->randomElement(['hikvision', 'zkteco']),
            'connection_config' => [
                'ip' => fake()->localIpv4(),
                'port' => 80,
                'username' => 'admin',
                'password' => 'admin123',
            ],
            'status' => 'online',
            'last_sync_at' => now(),
        ];
    }

    public function offline(): static
    {
        return $this->state([
            'status' => 'offline',
            'last_sync_at' => now()->subHours(2),
        ]);
    }

    public function hikvision(): static
    {
        return $this->state(['adapter_type' => 'hikvision']);
    }

    public function zkteco(): static
    {
        return $this->state(['adapter_type' => 'zkteco']);
    }

    public function mock(): static
    {
        return $this->state([
            'adapter_type' => 'mock',
            'connection_config' => [
                'ip' => '127.0.0.1',
                'port' => 0,
            ],
        ]);
    }
}

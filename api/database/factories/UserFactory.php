<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2519'.fake()->numerify('########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::EMPLOYEE,
            'status' => 'active',
            'locale' => 'en',
        ];
    }

    public function tenantAdmin(): static
    {
        return $this->state(['role' => UserRole::TENANT_ADMIN]);
    }

    public function hrAdmin(): static
    {
        return $this->state(['role' => UserRole::HR_ADMIN]);
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => UserRole::SUPER_ADMIN]);
    }

    public function withMfa(): static
    {
        return $this->state([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}

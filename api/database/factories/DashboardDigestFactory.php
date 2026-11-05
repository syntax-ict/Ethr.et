<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\DashboardDigest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DashboardDigest> */
class DashboardDigestFactory extends Factory
{
    protected $model = DashboardDigest::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => null,
            // The person who scheduled it, who must still see the dashboard
            // for it to run (RunDashboardDigestsJob).
            'created_by' => fn (array $attributes) => User::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'role' => UserRole::HR_ADMIN,
            ])->id,
            'frequency' => 'weekly',
            'recipients' => [fake()->safeEmail()],
            'next_run_at' => now()->addWeek(),
            'is_active' => true,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DashboardDigest;
use App\Models\Tenant;
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
            'frequency' => 'weekly',
            'recipients' => [fake()->safeEmail()],
            'next_run_at' => now()->addWeek(),
            'is_active' => true,
        ];
    }
}

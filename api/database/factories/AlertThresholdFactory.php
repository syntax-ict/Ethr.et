<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertThreshold;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertThreshold> */
class AlertThresholdFactory extends Factory
{
    protected $model = AlertThreshold::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'metric' => 'turnover_rate',
            'operator' => 'gt',
            'threshold_value' => 5.0,
            'severity' => 'warning',
            'is_active' => true,
        ];
    }
}

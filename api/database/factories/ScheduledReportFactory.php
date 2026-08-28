<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduledReport> */
class ScheduledReportFactory extends Factory
{
    protected $model = ScheduledReport::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'saved_report_id' => SavedReport::factory(),
            'frequency' => 'monthly',
            'recipients' => [fake()->safeEmail()],
            'next_run_at' => now()->addMonth(),
            'is_active' => true,
        ];
    }
}

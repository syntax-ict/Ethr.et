<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'price_cents' => 0,
                'max_employees' => 10,
                'max_branches' => 1,
                'max_devices' => 2,
                'features' => ['attendance', 'leave', 'employee_management'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'price_cents' => 99900,
                'max_employees' => 100,
                'max_branches' => 5,
                'max_devices' => 20,
                'features' => ['attendance', 'leave', 'employee_management', 'payroll', 'reports', 'notifications'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price_cents' => 299900,
                'max_employees' => 999999,
                'max_branches' => 999,
                'max_devices' => 999,
                'features' => ['attendance', 'leave', 'employee_management', 'payroll', 'reports', 'notifications', 'api_access', 'webhooks', 'custom_reports', 'audit_log'],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}

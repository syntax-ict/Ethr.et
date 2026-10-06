<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SavedReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedReport> */
class SavedReportFactory extends Factory
{
    protected $model = SavedReport::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'config' => [
                'source' => 'employees',
                'columns' => ['name', 'email', 'department'],
                'filters' => [],
            ],
            'created_by' => User::factory(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeaveBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveBalance> */
class LeaveBalanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'year' => now()->year,
            'entitled_days' => 20,
            'used_days' => 0,
            'carried_days' => 0,
            'pending_days' => 0,
        ];
    }
}

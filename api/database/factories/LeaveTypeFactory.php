<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccrualType;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LeaveType> */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => 'Annual Leave',
            'code' => 'annual',
            'default_days' => 20,
            'accrual_type' => AccrualType::ANNUAL,
            'carry_forward' => false,
            'requires_approval' => true,
            'requires_attachment' => false,
            'min_notice_days' => 3,
            'is_paid' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function sick(): static
    {
        return $this->state([
            'name' => 'Sick Leave',
            'code' => 'sick',
            'default_days' => 6,
            'min_notice_days' => 0,
            'requires_attachment' => true,
        ]);
    }

    public function maternity(): static
    {
        return $this->state([
            'name' => 'Maternity Leave',
            'code' => 'maternity',
            'default_days' => 120,
            'accrual_type' => AccrualType::ONE_TIME,
            'gender_restriction' => 'female',
            'min_notice_days' => 30,
        ]);
    }

    public function paternity(): static
    {
        return $this->state([
            'name' => 'Paternity Leave',
            'code' => 'paternity',
            'default_days' => 5,
            'accrual_type' => AccrualType::ONE_TIME,
            'gender_restriction' => 'male',
            'min_notice_days' => 0,
        ]);
    }

    public function monthly(): static
    {
        return $this->state([
            'accrual_type' => AccrualType::MONTHLY,
        ]);
    }

    public function withCarryForward(float $maxDays = 5.0): static
    {
        return $this->state([
            'carry_forward' => true,
            'max_carry_days' => $maxDays,
        ]);
    }
}

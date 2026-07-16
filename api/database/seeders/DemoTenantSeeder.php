<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EmployeeStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(['subdomain' => 'demo'], [
            'public_id' => (string) Str::ulid(),
            'name' => 'Ethio Demo Corp',
            'type' => 'private',
            'status' => TenantStatus::ACTIVE,
            'settings' => [
                'timezone' => 'Africa/Addis_Ababa',
                'locale' => 'en',
                'grace_period_minutes' => 15,
            ],
            'trial_ends_at' => now()->addMonths(6),
        ]);

        $adminUser = User::updateOrCreate(['email' => 'admin@demo.ethr.et'], [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'password' => bcrypt('password'),
            'role' => UserRole::TENANT_ADMIN,
            'status' => 'active',
            'locale' => 'en',
            'mfa_enabled' => false,
        ]);

        // HR admin user for E2E tests
        User::updateOrCreate(['email' => 'hr@demo.ethr.et'], [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'password' => bcrypt('password'),
            'role' => UserRole::HR_ADMIN,
            'status' => 'active',
            'locale' => 'en',
            'mfa_enabled' => false,
        ]);

        $hq = Branch::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'HQ'],
            [
                'public_id' => (string) Str::ulid(),
                'name' => 'Addis Ababa HQ',
                'address' => 'Bole, Addis Ababa',
                'is_active' => true,
            ],
        );

        $deptNames = ['Engineering', 'Finance', 'Human Resources', 'Operations', 'Sales'];
        $departments = [];
        foreach ($deptNames as $name) {
            $departments[] = Department::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => strtoupper(substr($name, 0, 3))],
                [
                    'public_id' => (string) Str::ulid(),
                    'name' => $name,
                    'branch_id' => $hq->id,
                    'is_active' => true,
                ],
            );
        }

        $positions = [];
        $posNames = ['Developer', 'Accountant', 'HR Officer', 'Manager', 'Sales Rep'];
        foreach ($posNames as $name) {
            $positions[] = Position::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => strtoupper(substr(str_replace(' ', '', $name), 0, 4))],
                [
                    'public_id' => (string) Str::ulid(),
                    'title' => $name,
                    'is_active' => true,
                ],
            );
        }

        $shift = Shift::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Regular'],
            [
                'public_id' => (string) Str::ulid(),
                'start_time' => '08:30',
                'end_time' => '17:30',
                'grace_minutes' => 15,
                'is_active' => true,
            ],
        );

        if (Employee::where('tenant_id', $tenant->id)->count() >= 100) {
            $this->command->info('Demo tenant already seeded — skipping bulk data.');

            return;
        }

        $employees = [];
        for ($i = 0; $i < 100; $i++) {
            $dept = $departments[array_rand($departments)];
            $pos = $positions[array_rand($positions)];

            $employees[] = Employee::create([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'name' => fake()->name(),
                'email' => fake()->unique()->safeEmail(),
                'phone' => '+2519'.fake()->numerify('########'),
                'employee_code' => 'EMP-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'gender' => fake()->randomElement(['male', 'female']),
                'date_of_birth' => fake()->date('Y-m-d', '-25 years'),
                'nationality' => 'Ethiopian',
                'marital_status' => fake()->randomElement(['single', 'married']),
                'status' => EmployeeStatus::CONFIRMED,
                'hire_date' => fake()->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
                'salary_cents' => fake()->numberBetween(300000, 3000000),
                'department_id' => $dept->id,
                'branch_id' => $hq->id,
                'position_id' => $pos->id,
            ]);
        }

        $leaveTypes = [
            LeaveType::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'AL'],
                [
                    'public_id' => (string) Str::ulid(),
                    'name' => 'Annual Leave',
                    'default_days' => 20,
                    'accrual_type' => 'monthly',
                    'is_active' => true,
                ],
            ),
            LeaveType::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'SL'],
                [
                    'public_id' => (string) Str::ulid(),
                    'name' => 'Sick Leave',
                    'default_days' => 10,
                    'accrual_type' => 'annual',
                    'is_active' => true,
                ],
            ),
        ];

        foreach ($employees as $employee) {
            foreach ($leaveTypes as $lt) {
                LeaveBalance::create([
                    'tenant_id' => $tenant->id,
                    'employee_id' => $employee->id,
                    'leave_type_id' => $lt->id,
                    'year' => now()->year,
                    'entitled_days' => $lt->default_days,
                    'used_days' => fake()->numberBetween(0, 5),
                    'carried_days' => 0,
                    'pending_days' => 0,
                ]);
            }
        }

        $holidays = [
            ['name' => 'Ethiopian New Year', 'date' => Carbon::parse(now()->year.'-09-11')],
            ['name' => 'Meskel', 'date' => Carbon::parse(now()->year.'-09-27')],
            ['name' => 'Ethiopian Christmas', 'date' => Carbon::parse(now()->year.'-01-07')],
        ];

        foreach ($holidays as $h) {
            Holiday::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $h['name']],
                [
                    'public_id' => (string) Str::ulid(),
                    'date' => $h['date'],
                    'recurring' => true,
                ],
            );
        }

        for ($month = 2; $month >= 0; $month--) {
            $monthStart = Carbon::now()->subMonths($month)->startOfMonth();
            $monthEnd = Carbon::now()->subMonths($month)->endOfMonth();

            $current = $monthStart->copy();
            while ($current->lte($monthEnd)) {
                if ($current->isWeekday()) {
                    foreach (array_slice($employees, 0, 90) as $employee) {
                        $late = fake()->boolean(10);
                        $checkIn = $current->copy()->setTime(8, $late ? fake()->numberBetween(35, 59) : fake()->numberBetween(15, 30));
                        $checkOut = $current->copy()->setTime(17, fake()->numberBetween(25, 50));

                        AttendanceRecord::create([
                            'public_id' => (string) Str::ulid(),
                            'tenant_id' => $tenant->id,
                            'employee_id' => $employee->id,
                            'shift_id' => $shift->id,
                            'date' => $current->format('Y-m-d'),
                            'check_in' => $checkIn->toDateTimeString(),
                            'check_out' => $checkOut->toDateTimeString(),
                            'source' => fake()->randomElement(['web', 'biometric', 'mobile']),
                            'status' => $late ? 'late' : 'present',
                            'confidence_score' => fake()->numberBetween(80, 100),
                        ]);
                    }
                }
                $current->addDay();
            }
        }

        // Employee self-service user (linked to first employee for E2E tests)
        if (! empty($employees)) {
            $firstEmp = $employees[0];
            User::updateOrCreate(['email' => 'emp@demo.ethr.et'], [
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'employee_id' => $firstEmp->id,
                'password' => bcrypt('password'),
                'role' => UserRole::EMPLOYEE,
                'status' => 'active',
                'locale' => 'en',
                'mfa_enabled' => false,
            ]);
        }

        $this->command->info('Demo tenant seeded: demo.ethr.et (admin@demo.ethr.et / password)');
        $this->command->info('HR admin: hr@demo.ethr.et / password');
        $this->command->info('Employee: emp@demo.ethr.et / password');
        $this->command->info('100 employees, 3 months attendance, leave balances, holidays');
    }
}

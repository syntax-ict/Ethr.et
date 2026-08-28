<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EmployeeStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The smallest dataset every screen can still be exercised against.
 *
 * DemoTenantSeeder builds 150 employees with six months of attendance and three
 * months of payroll — realistic, but slow to seed and slow to page through when
 * the question is only "does this screen work". This seeds one of each thing
 * instead: one tenant, one employee, one of every lookup a form needs to submit.
 *
 *   php artisan migrate:fresh --seed --seeder=Database\\Seeders\\MinimalTestSeeder
 *
 * Logins (all password `password`):
 *   superadmin@ethr.et   super_admin, no tenant
 *   admin@test.ethr.et   tenant_admin on the `test` tenant
 */
class MinimalTestSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions, plans, tax brackets and org templates are not sample
        // data — the app misbehaves without them, so they come first and in
        // full, exactly as DatabaseSeeder runs them.
        $this->call([
            PermissionSeeder::class,
            PlanSeeder::class,
            TaxBracketSeeder::class,
            OrganizationTemplateSeeder::class,
        ]);

        User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'superadmin@ethr.et', 'tenant_id' => null],
            [
                'public_id' => (string) Str::ulid(),
                'password' => bcrypt('password'),
                'role' => UserRole::SUPER_ADMIN,
                'status' => 'active',
                'locale' => 'en',
                'mfa_enabled' => false,
            ]
        );

        $tenant = Tenant::firstOrCreate(['subdomain' => 'test'], [
            'public_id' => (string) Str::ulid(),
            'name' => 'Test Org',
            'type' => 'private',
            'status' => TenantStatus::ACTIVE,
            'settings' => [
                'timezone' => 'Africa/Addis_Ababa',
                'locale' => 'en',
                'grace_period_minutes' => 15,
            ],
            'trial_ends_at' => now()->addMonths(6),
        ]);

        // BelongsToTenant's global scope filters every query below to zero rows
        // until this is set, which makes firstOrCreate() miss existing rows and
        // re-seeding die on unique constraints.
        app(CurrentTenant::class)->set($tenant);

        User::updateOrCreate(['email' => 'admin@test.ethr.et'], [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'password' => bcrypt('password'),
            'role' => UserRole::TENANT_ADMIN,
            'status' => 'active',
            'locale' => 'en',
            'mfa_enabled' => false,
        ]);

        $branch = Branch::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'HQ'],
            [
                'public_id' => (string) Str::ulid(),
                'name' => 'Head Office',
                'address' => 'Bole, Addis Ababa',
                'is_active' => true,
            ],
        );

        $department = Department::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'GEN'],
            [
                'public_id' => (string) Str::ulid(),
                'name' => 'General',
                'branch_id' => $branch->id,
                'is_active' => true,
            ],
        );

        $position = Position::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'STAF'],
            [
                'public_id' => (string) Str::ulid(),
                'title' => 'Staff',
                'is_active' => true,
            ],
        );

        Shift::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Regular'],
            [
                'public_id' => (string) Str::ulid(),
                'start_time' => '08:30',
                'end_time' => '17:30',
                'grace_minutes' => 15,
                'is_active' => true,
            ],
        );

        // Leave requests cannot be filed without a type to file them against.
        LeaveType::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'AL'],
            [
                'public_id' => (string) Str::ulid(),
                'name' => 'Annual Leave',
                'default_days' => 20,
                'accrual_type' => 'monthly',
                'is_active' => true,
            ],
        );

        Employee::firstOrCreate(
            ['tenant_id' => $tenant->id, 'employee_code' => 'EMP-0001'],
            [
                'public_id' => (string) Str::ulid(),
                'name' => 'Test Employee',
                'email' => 'employee@test.ethr.et',
                'phone' => '+251911000001',
                'gender' => 'female',
                'date_of_birth' => '1995-01-15',
                'nationality' => 'Ethiopian',
                'marital_status' => 'single',
                'status' => EmployeeStatus::CONFIRMED,
                'hire_date' => now()->subYear()->format('Y-m-d'),
                'salary_cents' => 1500000,
                'department_id' => $department->id,
                'branch_id' => $branch->id,
                'position_id' => $position->id,
            ],
        );

        $this->command?->info('Minimal test data: tenant `test`, admin@test.ethr.et / password, 1 employee.');
    }
}

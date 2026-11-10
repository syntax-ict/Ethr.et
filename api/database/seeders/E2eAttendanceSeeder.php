<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EmployeeStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Onboarding\OrganizationProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The organisations the attendance-to-payslip browser journeys run against
 * (src/e2e/attendance-payroll).
 *
 *     php artisan db:seed --class=E2eAttendanceSeeder
 *
 * Three small organisations, each with a fixed address, fixed sign-ins and a
 * biometric device whose webhook token the journeys know:
 *
 *   e2e-att    punches by several methods, read back from the API
 *   e2e-pay    a month of attendance paid through a payroll run
 *   e2e-other  somebody else's organisation, for the cross-tenant journey
 *
 * Not the demo tenant: that one holds months of random attendance and three
 * payroll runs, and a journey that asserts "one record today" or "one run for
 * this period" cannot share it.
 *
 * Every run starts clean. An organisation left by an earlier run is retired
 * (its address renamed, its devices unplugged, then closed) rather than
 * deleted: attendance and payroll rows are never deleted, and a fresh
 * organisation has none.
 *
 * Local and testing only. It refuses anywhere else, and is not part of
 * DatabaseSeeder or ProductionSeeder.
 */
class E2eAttendanceSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** @var array<string, array{name: string, people: list<array{code: string, name: string, badge: string, login: string|null, salary_cents: int}>, staff: array<string, UserRole>}> */
    private const ORGANISATIONS = [
        'e2e-att' => [
            'name' => 'E2E Attendance',
            'people' => [
                ['code' => 'ATT-0001', 'name' => 'Abebe Bekele', 'badge' => '9001', 'login' => 'emp1', 'salary_cents' => 1500000],
                ['code' => 'ATT-0002', 'name' => 'Tigist Haile', 'badge' => '9002', 'login' => null, 'salary_cents' => 1500000],
                ['code' => 'ATT-0003', 'name' => 'Dawit Tesfaye', 'badge' => '9003', 'login' => 'emp3', 'salary_cents' => 1500000],
            ],
            'staff' => ['admin' => UserRole::TENANT_ADMIN],
        ],
        'e2e-pay' => [
            'name' => 'E2E Payroll',
            'people' => [
                ['code' => 'PAY-0001', 'name' => 'Selam Girma', 'badge' => '9101', 'login' => null, 'salary_cents' => 2200000],
                ['code' => 'PAY-0002', 'name' => 'Yonas Alemu', 'badge' => '9102', 'login' => null, 'salary_cents' => 1100000],
                ['code' => 'PAY-0003', 'name' => 'Hana Mulugeta', 'badge' => '9103', 'login' => 'emp3', 'salary_cents' => 880000],
            ],
            'staff' => ['admin' => UserRole::TENANT_ADMIN, 'finance' => UserRole::FINANCE_ADMIN],
        ],
        'e2e-other' => [
            'name' => 'E2E Other Organisation',
            'people' => [
                ['code' => 'OTH-0001', 'name' => 'Meron Tadesse', 'badge' => '9201', 'login' => null, 'salary_cents' => 1000000],
            ],
            'staff' => ['admin' => UserRole::TENANT_ADMIN],
        ],
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('E2eAttendanceSeeder creates organisations with known passwords; it runs in local and testing only.');
        }

        foreach (self::ORGANISATIONS as $subdomain => $organisation) {
            $this->retire($subdomain);
            $this->create($subdomain, $organisation);
        }

        $this->command?->info('E2E organisations seeded: '.implode(', ', array_keys(self::ORGANISATIONS)).' (admin@{address}.ethr.test / '.self::PASSWORD.')');
    }

    /** The token a journey sends as `X-Webhook-Token` for the organisation's terminal. */
    public static function deviceToken(string $subdomain): string
    {
        return "{$subdomain}-zk-token";
    }

    /** An earlier run's organisation at this address steps aside for the new one. */
    private function retire(string $subdomain): void
    {
        $old = Tenant::withTrashed()->where('subdomain', $subdomain)->first();
        if ($old === null) {
            return;
        }

        app(CurrentTenant::class)->set($old);

        // The token and the live serial are unique across every organisation.
        foreach (Device::query()->where('tenant_id', $old->id)->get() as $device) {
            $device->update(['webhook_token' => null]);
            $device->delete();
        }

        $old->forceFill(['subdomain' => "{$subdomain}-retired-{$old->id}"])->save();
        if (! $old->trashed()) {
            $old->delete();
        }
    }

    /**
     * @param  array{name: string, people: list<array{code: string, name: string, badge: string, login: string|null, salary_cents: int}>, staff: array<string, UserRole>}  $organisation
     */
    private function create(string $subdomain, array $organisation): void
    {
        $tenant = Tenant::create([
            'public_id' => (string) Str::ulid(),
            'name' => $organisation['name'],
            'subdomain' => $subdomain,
            'type' => 'private',
            'status' => TenantStatus::ACTIVE,
            'timezone' => 'Africa/Addis_Ababa',
            'settings' => ['timezone' => 'Africa/Addis_Ababa', 'locale' => 'en'],
            'trial_ends_at' => now()->addMonths(6),
        ]);

        app(CurrentTenant::class)->set($tenant);

        foreach ($organisation['staff'] as $login => $role) {
            $this->user($tenant, $subdomain, $login, $role, null);
        }

        $branch = Branch::create([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'code' => 'HQ',
            'name' => 'Head Office',
            'address' => 'Bole, Addis Ababa',
            'is_active' => true,
        ]);

        $department = Department::create([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'code' => 'OPS',
            'name' => 'Operations',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        Shift::create([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'name' => 'Regular',
            'start_time' => '08:30',
            'end_time' => '17:30',
            'grace_minutes' => 15,
            'is_active' => true,
            'is_default' => true,
        ]);

        AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);

        // Unpaid, excused and business trip, as every organisation gets them.
        app(OrganizationProvisioner::class)->ensureSettlingLeaveTypes($tenant);

        foreach ($organisation['people'] as $person) {
            $employee = Employee::create([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'name' => $person['name'],
                'email' => Str::lower($person['code'])."@{$subdomain}.ethr.test",
                'phone' => '+25191100'.$person['badge'],
                'employee_code' => $person['code'],
                'badge_number' => $person['badge'],
                'gender' => 'female',
                'date_of_birth' => '1992-03-14',
                'nationality' => 'Ethiopian',
                'marital_status' => 'single',
                'status' => EmployeeStatus::CONFIRMED,
                'hire_date' => now()->subYears(2)->startOfMonth()->format('Y-m-d'),
                'salary_cents' => $person['salary_cents'],
                'department_id' => $department->id,
                'branch_id' => $branch->id,
            ]);

            if ($person['login'] !== null) {
                $this->user($tenant, $subdomain, $person['login'], UserRole::EMPLOYEE, $employee->id);
            }
        }

        Device::create([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Front Door Terminal',
            'serial_number' => 'E2E-'.Str::upper($subdomain),
            'adapter_type' => 'zkteco',
            'connection_config' => ['ip' => '192.0.2.10', 'port' => 4370],
            'status' => 'online',
            'webhook_token' => self::deviceToken($subdomain),
            // Nothing answers at that address; the journeys push, never pull.
            'auto_sync' => false,
            'timezone' => 'Africa/Addis_Ababa',
        ]);
    }

    private function user(Tenant $tenant, string $subdomain, string $login, UserRole $role, ?int $employeeId): void
    {
        User::create([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenant->id,
            'employee_id' => $employeeId,
            'email' => "{$login}@{$subdomain}.ethr.test",
            'password' => bcrypt(self::PASSWORD),
            'role' => $role,
            'status' => 'active',
            'locale' => 'en',
            'mfa_enabled' => false,
        ]);
    }
}

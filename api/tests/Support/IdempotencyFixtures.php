<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * Fixtures shared by tests/Feature/Idempotency and tests/Concurrency. A static
 * class, not global functions: Pest loads every test file into one process, and
 * a global helper name that two files share is a fatal error.
 */
final class IdempotencyFixtures
{
    /** Gross 8,000.00 ETB, a 200.00 loan instalment and cost sharing at 10 % (800.00). */
    public static function employeeWithBalances(Tenant $tenant): Employee
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'salary_cents' => 800000,
            'hire_date' => '2020-01-01',
        ]);

        EmployeeLoan::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'amount_cents' => 240000,
            'remaining_cents' => 240000,
            'monthly_deduction_cents' => 20000,
            'status' => 'active',
        ]);

        EmployeeCostSharing::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'deduction_rate_percent' => 10,
            'total_obligation_cents' => 5000000,
            'outstanding_cents' => 5000000,
        ]);

        return $employee;
    }

    public static function processRun(Tenant $tenant, int $userId): PayrollRun
    {
        return app(PayrollEngine::class)->process(
            $tenant->id,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
            $userId,
        )->run;
    }

    public static function runUrl(Tenant $tenant, PayrollRun $run, string $action): string
    {
        return "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/{$action}";
    }

    public static function apiUrl(Tenant $tenant, string $path): string
    {
        return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
    }

    public static function loanRemaining(Employee $employee): int
    {
        return (int) EmployeeLoan::where('employee_id', $employee->id)->firstOrFail()->remaining_cents;
    }

    public static function costSharingOutstanding(Employee $employee): int
    {
        return (int) EmployeeCostSharing::where('employee_id', $employee->id)->firstOrFail()->outstanding_cents;
    }
}

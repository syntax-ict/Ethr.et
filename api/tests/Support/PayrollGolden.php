<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * The two builders of the worked payslip tests, as static methods: Pest loads
 * every test file into one process, and a global function declared in a test
 * file is declared for all of them.
 */
final class PayrollGolden
{
    public static function employee(int $tenantId, string $code, int $basicCents): Employee
    {
        return Employee::factory()->create([
            'tenant_id' => $tenantId,
            'employee_code' => $code,
            'salary_cents' => $basicCents,
            'hire_date' => '2025-01-01',
            'status' => 'confirmed',
        ]);
    }

    public static function run(int $tenantId, int $userId): PayrollRun
    {
        return app(PayrollEngine::class)->process(
            $tenantId, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $userId,
        )->run;
    }
}

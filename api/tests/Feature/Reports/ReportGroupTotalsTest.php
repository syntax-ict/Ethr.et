<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Services\Report\ReportEngine;
use Carbon\Carbon;

/*
 * Group totals cover every matching row, not the 1,000 the file shows. A
 * grouped report over more rows than ROW_LIMIT counted and summed only the
 * newest 1,000 and said nothing (report review 2026-11-15, A1).
 */

/**
 * One employee's rows: a day each from `$start`, worked time known.
 *
 * @return array{0: list<array<string, mixed>>, 1: int}
 */
function groupTotalsRows(int $tenantId, int $employeeId, string $start, int $count): array
{
    $rows = [];
    $minutes = 0;
    for ($i = 0; $i < $count; $i++) {
        $day = Carbon::parse($start)->addDays($i);
        $worked = 400 + ($i % 50);
        $minutes += $worked;
        $rows[] = AttendanceRecord::factory()->make([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'date' => $day->format('Y-m-d'),
            'check_in' => $day->copy()->setTime(8, 0),
            'check_out' => $day->copy()->setTime(8, 0)->addMinutes($worked),
        ])->getAttributes();
    }

    return [$rows, $minutes];
}

test('a grouped attendance report counts and sums every matching row, not only the ones shown', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $ops = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations']);
    $fin = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    $a = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $ops->id]);
    $b = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $fin->id]);

    [$rowsA, $minutesA] = groupTotalsRows($tenant->id, $a->id, '2022-01-01', 700);
    [$rowsB, $minutesB] = groupTotalsRows($tenant->id, $b->id, '2022-01-01', 500);
    foreach (array_chunk([...$rowsA, ...$rowsB], 200) as $chunk) {
        AttendanceRecord::insert($chunk);
    }

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'attendance',
        'columns' => ['department', 'worked_minutes'],
        'group_by' => 'department',
    ])->assertOk();

    expect($response->json('truncated'))->toBeTrue()
        ->and(count($response->json('data')))->toBe(ReportEngine::ROW_LIMIT)
        ->and($response->json('matched'))->toBe(1200)
        ->and($response->json('summary.complete'))->toBeTrue()
        ->and($response->json('summary.groups.Operations'))->toBe(700)
        ->and($response->json('summary.groups.Finance'))->toBe(500)
        ->and($response->json('summary.group_sums.Operations.worked_minutes'))->toBe($minutesA)
        ->and($response->json('summary.group_sums.Finance.worked_minutes'))->toBe($minutesB);
});

test('a grouped report whose time ran out before it read a row says its totals are not complete', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    [$rows] = groupTotalsRows($tenant->id, $employee->id, '2022-01-01', ReportEngine::ROW_LIMIT + 50);
    foreach (array_chunk($rows, 200) as $chunk) {
        AttendanceRecord::insert($chunk);
    }
    config(['payroll.request_budget_seconds' => 0]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'attendance',
        'columns' => ['status'],
        'group_by' => 'status',
    ])->assertOk();

    expect($response->json('summary.complete'))->toBeFalse()
        ->and($response->json('truncated'))->toBeTrue()
        ->and(count($response->json('data')))->toBe(ReportEngine::ROW_LIMIT);
});

test('an ungrouped report says how many rows matched', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    [$rows] = groupTotalsRows($tenant->id, $employee->id, '2022-01-01', ReportEngine::ROW_LIMIT + 5);
    foreach (array_chunk($rows, 200) as $chunk) {
        AttendanceRecord::insert($chunk);
    }

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'attendance',
        'columns' => ['date'],
    ])->assertOk();

    expect($response->json('matched'))->toBe(ReportEngine::ROW_LIMIT + 5)
        ->and($response->json('total'))->toBe(ReportEngine::ROW_LIMIT)
        ->and($response->json('summary'))->toBe([]);
});

test('a grouped payroll report sums every payslip past the limit', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $entries = [];
    for ($i = 0; $i < ReportEngine::ROW_LIMIT + 20; $i++) {
        $entries[] = PayrollEntry::factory()->make([
            'tenant_id' => $tenant->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'income_tax_cents' => 100,
        ])->getAttributes();
    }
    foreach (array_chunk($entries, 200) as $chunk) {
        PayrollEntry::insert($chunk);
    }

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'payroll',
        'columns' => ['period', 'income_tax_cents'],
        'group_by' => 'period',
    ])->assertOk();

    expect($response->json('truncated'))->toBeTrue()
        ->and($response->json('matched'))->toBe(ReportEngine::ROW_LIMIT + 20)
        ->and($response->json('summary.complete'))->toBeTrue()
        ->and(array_sum($response->json('summary.groups')))->toBe(ReportEngine::ROW_LIMIT + 20)
        ->and(collect($response->json('summary.group_sums'))->sum('income_tax_cents'))->toBe((ReportEngine::ROW_LIMIT + 20) * 100);
});

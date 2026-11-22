<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\Shift;
use App\Services\Payroll\OvertimeCalculator;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * Two records covering the same hours pay their overtime once, in every
 * reconciliation mode: nothing voids the duplicate until someone resolves its
 * conflict, and the review is off by default. Overtime worked is still paid
 * whatever its approval or limit (Art. 68).
 */

/**
 * @param  list<array{string, string}>  $punches  local clock times [in, out] on 2026-06-02, one record each
 */
function overlapEntry(int $tenantId, int $userId, array $punches): array
{
    $employee = Employee::factory()->create(['tenant_id' => $tenantId, 'salary_cents' => 500000, 'hire_date' => '2020-01-01']);
    $shift = Shift::factory()->create(['tenant_id' => $tenantId]); // 08:30-17:30

    $records = [];
    foreach ($punches as [$in, $out]) {
        $records[] = AttendanceRecord::factory()->create([
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => '2026-06-02',
            'check_in' => Carbon::parse("2026-06-02 {$in}", 'Africa/Addis_Ababa')->utc(),
            'check_out' => Carbon::parse("2026-06-02 {$out}", 'Africa/Addis_Ababa')->utc(),
        ]);
    }

    $run = app(PayrollEngine::class)->process($tenantId, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $userId)->run;
    $entry = PayrollEntry::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');

    return [$step, $records];
}

test('two records of the same shift pay its overtime once', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    [$single] = overlapEntry($tenant->id, $user->id, [['08:30', '19:30']]);
    expect($single['overtime_minutes'])->toBe(120)
        ->and($single['overlapping_records_skipped'])->toBe([]);

    $tenant2 = createTenant();
    $user2 = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant2);
    [$step, $records] = overlapEntry($tenant2->id, $user2->id, [['08:30', '19:30'], ['08:31', '19:30']]);

    expect($step['overtime_minutes'])->toBe(120)
        ->and($step['overtime_amount_cents'])->toBe($single['overtime_amount_cents'])
        ->and($step['overlapping_records_skipped'])->toHaveCount(1);

    // Field by field rather than `toBe` on the whole entry. `calculation_log`
    // is a JSON column, and MySQL 8 stores an object's keys in its own order
    // (shorter first, then by bytes) while SQLite and MariaDB keep them as
    // written. A strict array comparison therefore passed `gates.sh backend`
    // and `gates.sh mysql` and failed only on the MySQL 8 job.
    $skipped = $step['overlapping_records_skipped'][0];
    expect($skipped['record'])->toBe($records[1]->public_id)
        ->and($skipped['kept'])->toBe($records[0]->public_id)
        ->and($skipped['date'])->toBe('2026-06-02');
});

test('a split day of two separate records still pays both in full', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 08:30-13:00 and 13:00-19:30: touching, not overlapping; 120 minutes of overtime in total.
    [$step] = overlapEntry($tenant->id, $user->id, [['08:30', '13:00'], ['13:00', '19:30']]);

    expect($step['overlapping_records_skipped'])->toBe([])
        ->and($step['overtime_minutes'])->toBe(120)
        ->and($step['overtime_amount_cents'])->toBe(app(OvertimeCalculator::class)->calculate(500000, 22, 8, 120, 'normal'));
});

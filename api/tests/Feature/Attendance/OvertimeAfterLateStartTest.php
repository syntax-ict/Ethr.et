<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\Shift;
use App\Services\Payroll\OvertimeClassifier;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/*
 * AR-OT-01 (docs/ATTENDANCE-RULES.md): on a working day, overtime is the time
 * worked after the shift's end, from the later of the shift's end and the
 * check-in, to the check-out. A record that begins after the shift's end earns
 * only what it worked, not the gap between the shift's end and its own start.
 *
 * Shift 08:30 to 17:30, break 60, Monday to Friday; 2026-06-15 is a Monday.
 */
afterEach(fn () => Carbon::setTestNow());

function otLateRecord(string $checkIn, string $checkOut, bool $night = false): AttendanceRecord
{
    $shift = new Shift($night
        ? ['start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true]
        : ['start_time' => '08:30', 'end_time' => '17:30', 'crosses_midnight' => false]);

    $record = new AttendanceRecord([
        'date' => '2026-06-15',
        'check_in' => Carbon::parse($checkIn, 'Africa/Addis_Ababa')->utc(),
        'check_out' => Carbon::parse($checkOut, 'Africa/Addis_Ababa')->utc(),
    ]);
    $record->setRelation('shift', $shift);

    return $record;
}

/** @return array<string, array{0: string, 1: string, 2: bool, 3: int, 4: int}> */
function otLateScenarios(): array
{
    return [
        'second session after the end' => ['2026-06-15 17:45', '2026-06-15 20:00', false, 135, 0],
        'record that begins after the end' => ['2026-06-15 18:00', '2026-06-15 20:00', false, 120, 0],
        'begins after the end, runs into the night' => ['2026-06-15 21:30', '2026-06-15 23:30', false, 30, 90],
        'unchanged: late finish of the ordinary day' => ['2026-06-15 08:40', '2026-06-15 19:00', false, 90, 0],
        'unchanged: out exactly at the end' => ['2026-06-15 08:30', '2026-06-15 17:30', false, 0, 0],
        'unchanged: night shift worked into the morning' => ['2026-06-15 21:58', '2026-06-16 07:30', true, 90, 0],
    ];
}

dataset('otLateScenarios', otLateScenarios());

test('overtimeMinutes counts only the time worked after the shift end', function (string $in, string $out, bool $night, int $normal, int $nightMinutes) {
    expect(otLateRecord($in, $out, $night)->overtimeMinutes())->toBe($normal + $nightMinutes);
})->with('otLateScenarios');

test('the classifier buckets only the time worked after the shift end', function (string $in, string $out, bool $night, int $normal, int $nightMinutes) {
    $buckets = (new OvertimeClassifier)->classify(
        record: otLateRecord($in, $out, $night),
        isHoliday: false,
        nightWindow: ['start_hour' => 22, 'end_hour' => 6],
    );

    expect($buckets['normal'])->toBe($normal)
        ->and($buckets['night'])->toBe($nightMinutes);
})->with('otLateScenarios');

test('payroll pays only the time worked after the shift end', function (string $in, string $out, bool $night, int $normal, int $nightMinutes) {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);
    $shift = Shift::factory()->create($night
        ? ['tenant_id' => $tenant->id, 'start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true, 'break_minutes' => 60]
        : ['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30', 'break_minutes' => 60]);

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-06-15',
        'check_in' => Carbon::parse($in, 'Africa/Addis_Ababa')->utc(),
        'check_out' => Carbon::parse($out, 'Africa/Addis_Ababa')->utc(),
    ]);

    $run = app(PayrollEngine::class)->process(
        $tenant->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $user->id,
    )->run;

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');

    expect($step['by_type']['normal']['minutes'])->toBe($normal)
        ->and($step['by_type']['night']['minutes'])->toBe($nightMinutes)
        ->and($step['overtime_minutes'])->toBe($normal + $nightMinutes);
})->with('otLateScenarios');

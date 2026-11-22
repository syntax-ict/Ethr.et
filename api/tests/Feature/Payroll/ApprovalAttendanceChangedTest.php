<?php

declare(strict_types=1);

use App\Enums\CorrectionKind;
use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Exceptions\PayrollRunRefused;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;

/**
 * Found 2026-11-21 (docs/audit/BASELINE.md §25al). Approval refused a run whose
 * salary moved since it was calculated, but only looked at attendance in an
 * organisation that requires reconciliation, and the default is off. So
 * overtime, lateness and leave decided after a run was calculated were paid at
 * the old figures, silently. Approval now recomputes, for the people whose
 * records were written since the run began, the figures attendance gives pay,
 * and refuses when one differs from the figure the entry logged.
 *
 * June 2026 starts on a Monday. The shift is 08:30 to 17:30, so a check-out at
 * 20:30 is 180 minutes of normal overtime.
 */
beforeEach(fn () => test()->travelTo(IlluminateCarbon::parse('2026-07-05 10:00', 'UTC')));

afterEach(fn () => IlluminateCarbon::setTestNow());

function approvalAddis(string $day, string $time): Carbon
{
    return Carbon::parse("{$day} {$time}", 'Africa/Addis_Ababa')->utc();
}

/** @return array{0: Tenant, 1: User, 2: Employee, 3: Shift} */
function approvalSetup(string $mode = 'off'): array
{
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    AttendanceSetting::updateOrCreate(['tenant_id' => $tenant->id], [...AttendanceSetting::defaults(), 'reconciliation_mode' => $mode]);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 500_000]);

    return [$tenant, $user, $employee, $shift];
}

function approvalRecord(Tenant $tenant, Employee $employee, Shift $shift, string $date, string $out = '17:30', array $extra = []): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => $date,
        'check_in' => approvalAddis($date, '08:30'),
        'check_out' => approvalAddis($date, $out),
        'metadata' => null,
        ...$extra,
    ]);
}

function approvalJune(Tenant $tenant, User $by): PayrollRun
{
    return app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $by->id)->run;
}

/** Calculated at 10:00; whatever the test changes is written an hour later. */
function approvalLater(): void
{
    test()->travelTo(IlluminateCarbon::parse('2026-07-05 11:00', 'UTC'));
}

function approvalOvertime(PayrollRun $run, Employee $employee): int
{
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();

    return (int) collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime')['overtime_minutes'];
}

function approvalRefusal(PayrollRun $run, int $userId, ?float $deadline = null): ?PayrollRunRefused
{
    try {
        app(PayrollEngine::class)->approve($run->fresh(), $userId, 0, $deadline);
    } catch (PayrollRunRefused $e) {
        return $e;
    }

    return null;
}

it('approves a run when attendance was only touched and no figure moved', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '20:30', ['photo_path' => 'photos/x.jpg']);
    $run = approvalJune($tenant, $user);
    expect(approvalOvertime($run, $employee))->toBe(180);

    approvalLater();
    $record->update(['photo_path' => null, 'metadata' => ['note' => 'duplicate punch logged']]);
    approvalRecord($tenant, $employee, $shift, '2026-06-03', '17:30');

    expect($record->fresh()->updated_at->gte(IlluminateCarbon::parse('2026-07-05 10:30', 'UTC')))->toBeTrue()
        ->and(approvalRefusal($run, $user->id))->toBeNull()
        ->and($run->fresh()->status)->toBe('approved');
});

it('refuses, with the people count, a run whose overtime grew when a correction was approved, and approves it once reprocessed', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup('off');
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $other = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 500_000]);
    approvalRecord($tenant, $other, $shift, '2026-06-02', '17:30');
    $run = approvalJune($tenant, $user);
    expect(approvalOvertime($run, $employee))->toBe(0);

    approvalLater();
    $record->update(['check_out' => approvalAddis('2026-06-02', '20:30')]);
    AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id, 'attendance_record_id' => $record->id, 'employee_id' => $employee->id,
        'kind' => CorrectionKind::MISSED_PUNCH, 'date' => '2026-06-02', 'status' => CorrectionStatus::APPROVED,
        'proposed_check_in' => null, 'proposed_check_out' => approvalAddis('2026-06-02', '20:30'),
    ]);

    $refused = approvalRefusal($run, $user->id);
    expect($refused)->toBeInstanceOf(PayrollRunRefused::class)
        ->and($refused->type)->toBe('https://ethr.et/errors/attendance-changed-since-processed')
        ->and($refused->getMessage())->toBe(__('payroll.attendance_changed_since_processed', ['count' => 1]))
        ->and($run->fresh()->status)->toBe('completed');

    app(PayrollEngine::class)->void($run, $user->id, 'attendance moved');
    $again = app(PayrollEngine::class)->reprocess($run->fresh(), $user->id)->run;
    app(PayrollEngine::class)->runEntries($again);

    expect(approvalOvertime($again, $employee))->toBe(180)
        ->and(approvalRefusal($again, $user->id))->toBeNull()
        ->and($again->fresh()->status)->toBe('approved');
});

it('answers 422 with the problem type through the approve route', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $run = approvalJune($tenant, $user);

    approvalLater();
    $record->update(['check_out' => approvalAddis('2026-06-02', '20:30')]);

    test()->putJson("/api/v1/payroll/runs/{$run->public_id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/attendance-changed-since-processed')
        ->assertJsonPath('detail', __('payroll.attendance_changed_since_processed', ['count' => 1]));
});

it('refuses a run when unpaid leave was approved after it was calculated', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    approvalRecord($tenant, $employee, $shift, '2026-06-02');
    $run = approvalJune($tenant, $user);

    approvalLater();
    $unpaid = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'is_paid' => false, 'code' => 'unpaid', 'name' => 'Unpaid']);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $unpaid->id,
        'start_date' => '2026-06-08', 'end_date' => '2026-06-09', 'days' => 2,
    ]);

    expect(approvalRefusal($run, $user->id)?->type)->toBe('https://ethr.et/errors/attendance-changed-since-processed')
        ->and($run->fresh()->status)->toBe('completed');
});

it('refuses a run when a real check-out replaced the automatic one', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30', ['metadata' => ['auto_checkout' => true]]);
    $run = approvalJune($tenant, $user);

    approvalLater();
    $record->update(['check_out' => approvalAddis('2026-06-02', '19:30'), 'metadata' => ['auto_checkout' => false]]);

    expect(approvalRefusal($run, $user->id)?->type)->toBe('https://ethr.et/errors/attendance-changed-since-processed');
});

it('approves when a merge voided a duplicate the run never paid twice', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    approvalRecord($tenant, $employee, $shift, '2026-06-02', '20:30');
    $duplicate = approvalRecord($tenant, $employee, $shift, '2026-06-02', '20:30');
    $run = approvalJune($tenant, $user);
    expect(approvalOvertime($run, $employee))->toBe(180);

    approvalLater();
    $duplicate->update(['status' => 'voided']);

    expect(approvalRefusal($run, $user->id))->toBeNull();
});

it('refuses a run when a holiday was announced on a day already worked', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    approvalRecord($tenant, $employee, $shift, '2026-06-02', '20:30');
    $run = approvalJune($tenant, $user);

    approvalLater();
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-06-02', 'is_active' => true]);

    expect(approvalRefusal($run, $user->id)?->type)->toBe('https://ethr.et/errors/attendance-changed-since-processed');
});

it('approves when a holiday was announced on a day nobody worked overtime', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $run = approvalJune($tenant, $user);

    approvalLater();
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-06-20', 'is_active' => true]);

    expect(approvalRefusal($run, $user->id))->toBeNull();
});

it('refuses a change committed while the run was being processed, whatever time the run says it started', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $engine = app(PayrollEngine::class);

    $run = $engine->begin($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $user->id)->run;
    test()->travelTo(IlluminateCarbon::parse('2026-07-05 10:05', 'UTC'));
    $engine->runEntries($run);

    // After the person's entry was calculated, before the run was finished.
    test()->travelTo(IlluminateCarbon::parse('2026-07-05 10:06', 'UTC'));
    $record->update(['check_out' => approvalAddis('2026-06-02', '20:30')]);

    // A run whose start was stamped after the change is still judged by its entries' own time.
    $run->forceFill(['processed_at' => IlluminateCarbon::parse('2026-07-05 10:30', 'UTC')])->saveQuietly();
    test()->travelTo(IlluminateCarbon::parse('2026-07-05 11:00', 'UTC'));

    expect(approvalRefusal($run, $user->id)?->type)->toBe('https://ethr.et/errors/attendance-changed-since-processed');
});

it('does not count a change that was committed before the person was calculated', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $engine = app(PayrollEngine::class);

    $run = $engine->begin($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $user->id)->run;
    test()->travelTo(IlluminateCarbon::parse('2026-07-05 10:01', 'UTC'));
    $record->update(['check_out' => approvalAddis('2026-06-02', '20:30')]);
    test()->travelTo(IlluminateCarbon::parse('2026-07-05 10:02', 'UTC'));
    $engine->runEntries($run);

    expect(approvalOvertime($run, $employee))->toBe(180)
        ->and(approvalRefusal($run, $user->id))->toBeNull();
});

it('refuses, and never approves, when the request ran out of time before everyone was checked', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $run = approvalJune($tenant, $user);

    approvalLater();
    $record->update(['photo_path' => 'photos/y.jpg']);

    $refused = approvalRefusal($run, $user->id, microtime(true) - 1);

    expect($refused)->toBeInstanceOf(PayrollRunRefused::class)
        ->and($refused->type)->toBe('https://ethr.et/errors/attendance-check-timed-out')
        ->and($refused->getMessage())->toBe(__('payroll.attendance_check_timed_out'))
        ->and($run->fresh()->status)->toBe('completed');
});

it('skips an entry calculated before the log carried every compared figure', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    $record = approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $run = approvalJune($tenant, $user);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->firstOrFail();
    $log = $entry->calculation_log;
    $log['version'] = '1.8';
    $entry->forceFill(['calculation_log' => $log])->saveQuietly();

    approvalLater();
    $record->update(['check_out' => approvalAddis('2026-06-02', '20:30')]);

    expect(approvalRefusal($run, $user->id))->toBeNull()
        ->and($run->fresh()->status)->toBe('approved');
});

it('does not count a change in another organisation', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    approvalRecord($tenant, $employee, $shift, '2026-06-02', '17:30');
    $run = approvalJune($tenant, $user);

    approvalLater();
    $otherTenant = createTenant();
    $otherShift = Shift::factory()->create(['tenant_id' => $otherTenant->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $otherTenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 500_000]);
    approvalRecord($otherTenant, $stranger, $otherShift, '2026-06-02', '20:30');
    Holiday::factory()->create(['tenant_id' => $otherTenant->id, 'branch_id' => null, 'date' => '2026-06-02', 'is_active' => true]);
    $otherShift->update(['name' => 'Renamed']);

    app(CurrentTenant::class)->set($tenant);

    expect(approvalRefusal($run, $user->id))->toBeNull();
});

it('refuses a run when a shift it was paid on moved a worked day to the rest day rate', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    approvalRecord($tenant, $employee, $shift, '2026-06-02', '20:30');
    $run = approvalJune($tenant, $user);

    approvalLater();
    $shift->update(['working_days' => '1,3,4,5']);

    expect(approvalRefusal($run, $user->id)?->type)->toBe('https://ethr.et/errors/attendance-changed-since-processed');
});

it('approves a run when a shift it was paid on was only renamed', function () {
    [$tenant, $user, $employee, $shift] = approvalSetup();
    approvalRecord($tenant, $employee, $shift, '2026-06-02', '20:30');
    $run = approvalJune($tenant, $user);

    approvalLater();
    $shift->update(['name' => 'Day shift, renamed']);

    expect(approvalRefusal($run, $user->id))->toBeNull();
});

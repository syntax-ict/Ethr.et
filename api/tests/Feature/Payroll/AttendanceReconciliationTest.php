<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\CorrectionKind;
use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\AttendanceReconciliation;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\RunRegisterService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/*
 * Attendance → payroll reconciliation, PR C (2026-10-27). One read-only
 * service lists every exception in a pay period with whether someone acted on
 * it; payroll deducts approved lateness, capped at a third of basic; and an
 * organisation that requires reconciliation cannot approve a run while
 * anything is open, or once something was decided after it was calculated.
 *
 * June 2026 starts on a Monday and has 22 Monday-to-Friday workdays. Salary
 * 500,000 cents: the daily rate is round(500000 / 22) = 22,727 cents, the
 * minute rate 22,727 / 480 = 47.348 cents.
 */

beforeEach(fn () => test()->travelTo(Carbon::parse('2026-07-05 10:00', 'Africa/Addis_Ababa')));

function reconUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function reconAddis(string $day, string $time): Carbon
{
    return Carbon::parse("{$day} {$time}", 'Africa/Addis_Ababa')->utc();
}

function reconSettings(Tenant $tenant, array $attributes): void
{
    AttendanceSetting::updateOrCreate(['tenant_id' => $tenant->id], [...AttendanceSetting::defaults(), ...$attributes]);
}

/**
 * An employee on an 08:30-17:30 shift present on every June workday except
 * $absent, and 40 minutes late on $late.
 *
 * @param  list<string>  $absent
 */
function reconEmployee(Tenant $tenant, array $absent = [], ?string $late = null): Employee
{
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id, 'start_time' => '08:30', 'end_time' => '17:30', 'grace_minutes' => 15,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 500_000]);

    foreach (CarbonPeriod::create('2026-06-01', '2026-06-30') as $day) {
        $date = $day->format('Y-m-d');
        if ($day->isWeekend() || in_array($date, $absent, true)) {
            continue;
        }
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $date,
            'status' => $date === $late ? AttendanceStatus::LATE : AttendanceStatus::PRESENT,
            'check_in' => reconAddis($date, $date === $late ? '09:10' : '08:30'),
            'check_out' => reconAddis($date, '17:30'),
            'metadata' => null,
        ]);
    }

    return $employee;
}

function reconJune(Tenant $tenant, User $by): PayrollRun
{
    return app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $by->id)->run;
}

/** @return array<string, mixed>|null */
function reconStep(PayrollRun $run, Employee $employee, string $step): ?array
{
    $entry = PayrollEntry::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();

    return collect($entry->calculation_log['steps'])->firstWhere('step', $step);
}

function reconLateness(Tenant $tenant, Employee $employee, string $day, int $minutes, CorrectionStatus $status = CorrectionStatus::PENDING): AttendanceCorrection
{
    $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', $day)->firstOrFail();

    if ($status === CorrectionStatus::APPROVED) {
        $record->update(['metadata' => ['pay_deduct_minutes' => $minutes, 'lateness_correction_id' => 'L-'.$day]]);
    }

    return AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'kind' => CorrectionKind::LATENESS,
        'date' => $day,
        'proposed_check_in' => null,
        'proposed_check_out' => null,
        // The request allows at most a day's minutes; the cap test puts a larger
        // total on the record, which is what payroll reads.
        'deduct_minutes' => min($minutes, 1440),
        'status' => $status,
    ]);
}

describe('payroll', function () {
    it('pays exactly as before for an organisation that does not deduct lateness', function () {
        $tenant = createTenant();
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant, late: '2026-06-10');

        $run = reconJune($tenant, $finance);

        expect(reconStep($run, $employee, 'lateness'))->toBeNull()
            ->and(PayrollEntry::where('payroll_run_id', $run->id)->value('basic_salary_cents'))->toBe(500_000);
    });

    it('deducts approved lateness at the minute rate, and nothing for a pending decision', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules 12']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant, late: '2026-06-10');
        reconLateness($tenant, $employee, '2026-06-10', 40, CorrectionStatus::APPROVED);
        reconLateness($tenant, $employee, '2026-06-11', 30);

        $run = reconJune($tenant, $finance);
        $step = reconStep($run, $employee, 'lateness');

        // round(40 * 22727 / 480) = 1,894 cents.
        expect($step['deducted_minutes'])->toBe(40)
            ->and($step['deduction_cents'])->toBe(1894)
            ->and($step['capped'])->toBeFalse()
            ->and(PayrollEntry::where('payroll_run_id', $run->id)->value('basic_salary_cents'))->toBe(500_000 - 1894);
    });

    it('never deducts more than a third of the period basic', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules 12']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant, late: '2026-06-10');
        reconLateness($tenant, $employee, '2026-06-10', 100_000, CorrectionStatus::APPROVED);

        $step = reconStep(reconJune($tenant, $finance), $employee, 'lateness');

        expect($step['capped'])->toBeTrue()
            ->and($step['deduction_cents'])->toBe(intdiv(500_000, 3));
    });
});

describe('the one-third ceiling, shared with loans and cost sharing', function () {
    it('gives lateness only the headroom a loan leaves, and leaves the loan as it was', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules 12']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant, late: '2026-06-10');
        EmployeeLoan::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'amount_cents' => 1_000_000, 'remaining_cents' => 1_000_000, 'monthly_deduction_cents' => 150_000,
        ]);
        reconLateness($tenant, $employee, '2026-06-10', 100_000, CorrectionStatus::APPROVED);

        $run = reconJune($tenant, $finance);
        $step = reconStep($run, $employee, 'lateness');

        // A third of 500,000 is 166,666; the loan takes 150,000 of it.
        expect($step['third_of_basic_cents'])->toBe(166_666)
            ->and($step['other_deductions_cents'])->toBe(150_000)
            ->and($step['deduction_cents'])->toBe(16_666)
            ->and(reconStep($run, $employee, 'loan_deductions')['loan_deduction_cents'])->toBe(150_000);
    });

    it('deducts no lateness when loans already take the third', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules 12']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant, late: '2026-06-10');
        EmployeeLoan::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'amount_cents' => 1_000_000, 'remaining_cents' => 1_000_000, 'monthly_deduction_cents' => 200_000,
        ]);
        reconLateness($tenant, $employee, '2026-06-10', 40, CorrectionStatus::APPROVED);

        $step = reconStep(reconJune($tenant, $finance), $employee, 'lateness');

        expect($step['cap_cents'])->toBe(0)
            ->and($step['deduction_cents'])->toBe(0)
            ->and($step['capped'])->toBeTrue();
    });

    it('lists on the register a payslip whose actual total still passes the third', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules 12']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant, late: '2026-06-10');
        // A loan already over the third: it was there before lateness was,
        // and is not cut. The register says so.
        EmployeeLoan::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'amount_cents' => 1_000_000, 'remaining_cents' => 1_000_000, 'monthly_deduction_cents' => 200_000,
        ]);

        $ceiling = app(RunRegisterService::class)->register(reconJune($tenant, $finance))['deduction_ceiling'];

        expect($ceiling['count'])->toBe(1)
            ->and($ceiling['employees'][0]['ceiling_cents'])->toBe(166_666)
            ->and($ceiling['employees'][0]['deducted_cents'])->toBe(200_000);
    });

    it('lists nothing for an organisation that does not deduct lateness', function () {
        $tenant = createTenant();
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant);
        EmployeeLoan::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'amount_cents' => 1_000_000, 'remaining_cents' => 1_000_000, 'monthly_deduction_cents' => 200_000,
        ]);

        expect(app(RunRegisterService::class)->register(reconJune($tenant, $finance))['deduction_ceiling']['count'])->toBe(0);
    });
});

describe('the reconciliation', function () {
    it('lists each exception with whether someone acted on it', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules 12']);
        $employee = reconEmployee($tenant, absent: ['2026-06-03', '2026-06-04'], late: '2026-06-10');
        AttendanceCorrection::factory()->create([
            'tenant_id' => $tenant->id,
            'attendance_record_id' => null,
            'employee_id' => $employee->id,
            'kind' => CorrectionKind::MISSED_PUNCH,
            'date' => '2026-06-04',
            'proposed_check_in' => reconAddis('2026-06-04', '08:30'),
        ]);
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        $body = test()->getJson(reconUrl($tenant, 'payroll/reconciliation?period_start=2026-06-01&period_end=2026-06-30'))
            ->assertOk()
            ->json();

        $exceptions = collect($body['employees'][0]['exceptions'])->keyBy(fn ($e) => $e['type'].'@'.$e['date']);

        expect($exceptions['unexplained_absence@2026-06-03']['state'])->toBe('open')
            ->and($exceptions['unexplained_absence@2026-06-04']['state'])->toBe('pending')
            ->and($exceptions['unexplained_absence@2026-06-04']['request_kind'])->toBe('correction')
            ->and($exceptions['late_arrival@2026-06-10']['state'])->toBe('open')
            ->and($exceptions['late_arrival@2026-06-10']['minutes'])->toBe(40)
            ->and($body['totals']['blocking'])->toBe(3)
            ->and($body['mode'])->toBe('off');
    });

    it('lists no lateness for an organisation that does not deduct it', function () {
        $tenant = createTenant();
        reconEmployee($tenant, late: '2026-06-10');
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        test()->getJson(reconUrl($tenant, 'payroll/reconciliation?period_start=2026-06-01&period_end=2026-06-30'))
            ->assertOk()
            ->assertJsonPath('totals.blocking', 0)
            ->assertJsonPath('employees', []);
    });

    it('sees a decision filed after it was read, though the answer is cached', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules 12']);
        $employee = reconEmployee($tenant, late: '2026-06-10');
        $service = app(AttendanceReconciliation::class);

        $before = $service->forPeriod($tenant, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        reconLateness($tenant, $employee, '2026-06-10', 40);
        $after = $service->forPeriod($tenant, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

        expect($before['totals']['open'])->toBe(1)
            ->and($after['totals']['open'])->toBe(0)
            ->and($after['totals']['pending'])->toBe(1);
    });

    it('is not open to someone who cannot read payroll', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(reconUrl($tenant, 'payroll/reconciliation?period_start=2026-06-01&period_end=2026-06-30'))
            ->assertForbidden();
    });

    it('shows one organisation nothing of another', function () {
        $other = createTenant(['subdomain' => 'other']);
        reconEmployee($other, absent: ['2026-06-03']);

        $tenant = createTenant(['subdomain' => 'mine']);
        reconEmployee($tenant);
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        test()->getJson(reconUrl($tenant, 'payroll/reconciliation?period_start=2026-06-01&period_end=2026-06-30'))
            ->assertOk()
            ->assertJsonPath('employees', []);
    });
});

it('finds for everyone at once exactly the absences payroll finds person by person', function () {
    $tenant = createTenant();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $absent = reconEmployee($tenant, absent: ['2026-06-03', '2026-06-04', '2026-06-11', '2026-06-15']);
    $hiredLate = reconEmployee($tenant, absent: ['2026-06-02', '2026-06-22']);
    $hiredLate->update(['hire_date' => '2026-06-15']);
    $present = reconEmployee($tenant);
    $paid = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'is_paid' => true]);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $absent->id, 'leave_type_id' => $paid->id,
        'start_date' => '2026-06-04', 'end_date' => '2026-06-04', 'days' => 1,
    ]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-06-11', 'is_active' => true]);

    $engine = app(PayrollEngine::class);
    $start = Carbon::parse('2026-06-01');
    $end = Carbon::parse('2026-06-30');
    $employees = Employee::query()->whereIn('id', [$absent->id, $hiredLate->id, $present->id])->get();

    $batch = $engine->unexplainedAbsencesFor($employees, $start, $end);

    foreach ($employees as $employee) {
        expect($batch[$employee->id] ?? [])->toBe($engine->unexplainedAbsences($employee->fresh(), $start, $end));
    }
    expect($batch[$absent->id])->toBe(['2026-06-03', '2026-06-15'])
        ->and($batch[$hiredLate->id])->toBe(['2026-06-22'])
        ->and($batch)->not->toHaveKey($present->id);
});

describe('an employee', function () {
    it('sees their own unexplained absences, and no one else\'s', function () {
        $tenant = createTenant();
        $self = reconEmployee($tenant, absent: ['2026-06-03']);
        reconEmployee($tenant, absent: ['2026-06-05']);
        test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $self->id], $tenant));

        test()->getJson(reconUrl($tenant, 'attendance/my-exceptions?period_start=2026-06-01&period_end=2026-06-30'))
            ->assertOk()
            ->assertJsonCount(1, 'exceptions')
            ->assertJsonPath('exceptions.0.type', 'unexplained_absence')
            ->assertJsonPath('exceptions.0.date', '2026-06-03')
            ->assertJsonPath('exceptions.0.state', 'open');
    });
});

describe('approving a run', function () {
    it('is refused while exceptions are open when the organisation requires reconciliation', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['reconciliation_mode' => 'required']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        reconEmployee($tenant, absent: ['2026-06-03']);
        $run = reconJune($tenant, $finance);

        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        test()->putJson(reconUrl($tenant, "payroll/runs/{$run->public_id}/approve"))
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://ethr.et/errors/reconciliation-open');

        expect($run->fresh()->status)->toBe('completed');
    });

    it('goes ahead in advisory mode', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['reconciliation_mode' => 'advisory']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        reconEmployee($tenant, absent: ['2026-06-03']);
        $run = reconJune($tenant, $finance);

        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        test()->putJson(reconUrl($tenant, "payroll/runs/{$run->public_id}/approve"))->assertOk();
    });

    it('is refused when a decision was made after the run was calculated', function () {
        $tenant = createTenant();
        reconSettings($tenant, ['reconciliation_mode' => 'required']);
        $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        $employee = reconEmployee($tenant);
        $run = reconJune($tenant, $finance);

        test()->travel(5)->minutes();
        $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', '2026-06-10')->firstOrFail();
        AttendanceCorrection::factory()->approved()->create([
            'tenant_id' => $tenant->id,
            'attendance_record_id' => $record->id,
            'employee_id' => $employee->id,
            'date' => '2026-06-10',
        ]);

        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        test()->putJson(reconUrl($tenant, "payroll/runs/{$run->public_id}/approve"))
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://ethr.et/errors/reconciliation-moved');
    });
});

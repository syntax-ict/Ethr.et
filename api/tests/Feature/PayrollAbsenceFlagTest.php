<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\RunRegisterService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/*
 * Workdays missed with no attendance, leave or holiday on record are listed
 * on the payroll run before approval, and pay is not reduced for them. A day
 * the system cannot explain may be unrecorded leave, a missed punch or work
 * away from site; docking pay is a decision for a person (owner delegation,
 * 2026-10-24).
 *
 * June 2026 starts on a Monday and has 22 Monday-to-Friday workdays.
 */

beforeEach(function () {
    test()->travelTo(Carbon::parse('2026-07-05 10:00', 'Africa/Addis_Ababa'));
});

/** Attendance on every Monday-to-Friday in June except the given days. */
function attendJune(Tenant $tenant, Employee $employee, array $except = []): void
{
    foreach (CarbonPeriod::create('2026-06-01', '2026-06-30') as $day) {
        if ($day->isWeekend() || in_array($day->format('Y-m-d'), $except, true)) {
            continue;
        }
        AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => $day->format('Y-m-d')]);
    }
}

function runJune(Tenant $tenant, User $actor): PayrollRun
{
    return app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $actor->id)->run;
}

test('missed workdays are flagged, leave and holidays are not, and pay is unchanged', function () {
    $tenant = createTenant();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $missing = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 1_000_000]);
    $present = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 1_000_000]);

    attendJune($tenant, $missing, ['2026-06-10', '2026-06-11', '2026-06-12', '2026-06-15']);
    attendJune($tenant, $present);

    $paidLeave = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'is_paid' => true]);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $missing->id,
        'leave_type_id' => $paidLeave->id,
        'start_date' => '2026-06-12',
        'end_date' => '2026-06-12',
        'days' => 1,
    ]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-06-15', 'is_active' => true]);

    $run = runJune($tenant, $finance);

    $entries = PayrollEntry::query()->where('payroll_run_id', $run->id)->get()->keyBy('employee_id');
    // Earned basic is where a deduction for days not worked would land, and
    // it is the full month for both. Net differs only by the overtime the
    // factory's random punch times produce.
    expect($entries[$missing->id]->basic_salary_cents)->toBe(1_000_000)
        ->and($entries[$present->id]->basic_salary_cents)->toBe(1_000_000);

    $absences = app(RunRegisterService::class)->register($run)['absences'];
    expect($absences['count'])->toBe(1)
        ->and($absences['days'])->toBe(2)
        ->and($absences['employees'][0]['employee_public_id'])->toBe($missing->public_id)
        ->and($absences['employees'][0]['dates'])->toBe(['2026-06-10', '2026-06-11']);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/register")
        ->assertOk()
        ->assertJsonPath('absences.count', 1)
        ->assertJsonPath('absences.employees.0.days', 2);
});

test('an organisation that records no attendance has nothing flagged', function () {
    $tenant = createTenant();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 1_000_000]);

    $absences = app(RunRegisterService::class)->register(runJune($tenant, $finance))['absences'];

    expect($absences['count'])->toBe(0);
});

test('only days already over can be absences', function () {
    // Mid-June: the run is processed early, and the 15th onward has not happened.
    test()->travelTo(Carbon::parse('2026-06-15 10:00', 'Africa/Addis_Ababa'));
    $tenant = createTenant();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'salary_cents' => 1_000_000]);
    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => '2026-06-01']);

    $dates = app(RunRegisterService::class)->register(runJune($tenant, $finance))['absences']['employees'][0]['dates'];

    expect($dates)->toBe(['2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11', '2026-06-12']);
});

test('a run from before the step existed reports no absences', function () {
    expect(RunRegisterService::absences([['employee_public_id' => 'x', 'employee_name' => 'A', 'unexplained_absence_days' => null]]))
        ->toBe(['count' => 0, 'days' => 0, 'employees' => []]);
});

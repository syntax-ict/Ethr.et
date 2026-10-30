<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use Carbon\Carbon;

// The four analytics figures that read wrong until 2026-10-29: payroll counted
// voided runs, the hires chart repeated a month on the 31st, the attendance
// charts counted voided records, and the attendance rate's base was every
// weekday whoever was expected.

function metricFixUrl(string $subdomain, string $path = ''): string
{
    return "http://{$subdomain}.ethr.test/api/v1/dashboard/executive{$path}";
}

function metricFixAttend(Employee $employee, string $date, array $state = []): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => Carbon::parse("{$date} 08:00"),
        'check_out' => Carbon::parse("{$date} 17:00"),
        ...$state,
    ]);
}

test('payroll figures count neither a voided nor an unfinished run', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'department_id' => $department->id,
    ]);

    // One month run three times: voided, re-run, and a run still processing.
    foreach (['voided' => 100000, 'completed' => 250000, 'processing' => 400000] as $status => $gross) {
        $run = PayrollRun::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => $status,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'gross_total_cents' => $gross,
            'net_total_cents' => $gross,
        ]);
        PayrollEntry::factory()->create([
            'tenant_id' => $tenant->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross_cents' => $gross,
            'net_cents' => $gross,
        ]);
    }

    foreach (['', "?branch={$branch->public_id}"] as $scope) {
        $overview = test()->getJson(metricFixUrl($tenant->subdomain, $scope))->assertOk();
        $payroll = test()->getJson(metricFixUrl($tenant->subdomain, "/payroll{$scope}"))->assertOk();

        expect($overview->json('payroll_summary.gross_cents'))->toBe(250000)
            ->and($payroll->json('totals.total_gross_cents'))->toBe(250000)
            ->and(collect($payroll->json('by_department'))->sum('total_gross_cents'))->toBe(250000)
            ->and(collect($payroll->json('by_cost_center'))->sum('total_gross_cents'))->toBe(250000);
    }
});

test('the hires chart names six different months on the 31st', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->travelTo(Carbon::parse('2026-10-31 10:00'));

    $months = collect(test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('workforce_growth'))
        ->pluck('month')
        ->all();

    expect($months)->toBe(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10']);
});

test('the attendance charts leave out voided records', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->travelTo(Carbon::parse('2026-10-14 12:00'));
    $kept = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $merged = Employee::factory()->create(['tenant_id' => $tenant->id]);

    metricFixAttend($kept, '2026-10-14');
    metricFixAttend($merged, '2026-10-14', ['status' => AttendanceStatus::VOIDED, 'source' => AttendanceSource::BIOMETRIC]);

    $attendance = test()->getJson(metricFixUrl($tenant->subdomain, '/attendance'))->assertOk();

    expect($attendance->json('daily_trend'))->toBe([['date' => '2026-10-14', 'present' => 1]])
        ->and($attendance->json('by_source'))->toBe([['source' => AttendanceSource::WEB->value, 'count' => 1]]);
});

test('attendance rate counts only the days people were expected, up to today', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // Wednesday 14 October, a Monday-to-Friday week. One employee checked in
    // on every workday so far, the other on none of them.
    $this->travelTo(Carbon::parse('2026-10-14 12:00', 'Africa/Addis_Ababa'));
    $present = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    foreach (Carbon::parse('2026-10-01')->daysUntil('2026-10-14') as $day) {
        if ($day->isWeekday()) {
            metricFixAttend($present, $day->toDateString());
        }
    }

    // Ten workdays from the 1st to the 14th, two people expected on each:
    // half. Counting all 22 weekdays of October gave 22.7.
    expect(test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('attendance_rate'))
        ->toBe(['today' => 50, 'period' => 50]);
});

test('attendance rate is null on a day no one is expected', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Africa/Addis_Ababa'));
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    expect(test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('attendance_rate.today'))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
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
    // Gregorian: the runs below cover the Gregorian month, and so must the default period.
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);
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
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->travelTo(Carbon::parse('2026-10-31 10:00'));

    $months = collect(test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('workforce_growth'))
        ->pluck('month')
        ->all();

    expect($months)->toBe(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10']);
});

test('the hires chart counts Ethiopian months for an organisation on the Ethiopian calendar', function () {
    // ProvisionTenant's default; the factory states no calendar.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // 31 October 2026 is Tikimt 20, 2019. Pagume 2018 (6 to 10 September) is
    // a month of its own, as the Analytics page counts months.
    $this->travelTo(Carbon::parse('2026-10-31 10:00'));
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-10-11']); // Tikimt 1
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-10-10']); // Meskerem 30
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-09-08']); // Pagume 3

    $growth = test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('workforce_growth');

    expect(collect($growth)->pluck('month')->all())
        ->toBe(['2018-10-EC', '2018-11-EC', '2018-12-EC', '2018-13-EC', '2019-01-EC', '2019-02-EC'])
        ->and(collect($growth)->pluck('hires')->all())->toBe([0, 0, 0, 1, 1, 1]);
});

test('the hires chart carries the headcount at the end of each month', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->travelTo(Carbon::parse('2026-10-20 10:00'));
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-08-15']);
    // Hired in September, left in October: counted at September's end only.
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'hire_date' => '2026-09-01',
        'termination_date' => '2026-10-05',
        'status' => EmployeeStatus::RESIGNED,
    ]);

    $growth = test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('workforce_growth');

    expect(collect($growth)->pluck('headcount')->all())->toBe([1, 1, 1, 2, 3, 2]);
});

test('the default period is the current month of the organisation calendar', function () {
    // 3 November 2026 is Tikimt 23, 2019: Tikimt runs 11 October to 9 November.
    $this->travelTo(Carbon::parse('2026-11-03 12:00', 'Africa/Addis_Ababa'));

    $ethiopian = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $ethiopian);
    $person = Employee::factory()->create(['tenant_id' => $ethiopian->id]);
    foreach (['2026-10-10', '2026-10-11', '2026-10-31', '2026-11-03'] as $day) {
        metricFixAttend($person, $day);
    }

    expect(collect(test()->getJson(metricFixUrl($ethiopian->subdomain, '/attendance'))->assertOk()->json('daily_trend'))->pluck('date')->all())
        ->toBe(['2026-10-11', '2026-10-31', '2026-11-03']);

    app('auth')->forgetGuards();

    $gregorian = createTenant(['settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $gregorian);
    $person = Employee::factory()->create(['tenant_id' => $gregorian->id]);
    foreach (['2026-10-31', '2026-11-01', '2026-11-03'] as $day) {
        metricFixAttend($person, $day);
    }

    expect(collect(test()->getJson(metricFixUrl($gregorian->subdomain, '/attendance'))->assertOk()->json('daily_trend'))->pluck('date')->all())
        ->toBe(['2026-11-01', '2026-11-03']);

    // An explicit period is taken as given in either calendar.
    expect(collect(test()->getJson(metricFixUrl($gregorian->subdomain, '/attendance?from=2026-10-11&to=2026-11-09'))->assertOk()->json('daily_trend'))->pluck('date')->all())
        ->toBe(['2026-10-31', '2026-11-01', '2026-11-03']);
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
        ->toMatchArray(['today' => 50, 'period' => 50, 'today_present' => 1, 'today_expected' => 2]);
});

test('the attendance rate counts today in people', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // Wednesday 14 October, 07:00. Three people expected; two came in
    // yesterday, nobody has checked in yet today.
    $this->travelTo(Carbon::parse('2026-10-14 07:00', 'Africa/Addis_Ababa'));
    [$first, $second] = Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01'])->all();
    metricFixAttend($first, '2026-10-13');
    metricFixAttend($second, '2026-10-13');

    $rate = test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('attendance_rate');

    // 0 of 3 in is a count, not an empty office; the rate still says 0.
    expect($rate['today_present'])->toBe(0)
        ->and($rate['today_expected'])->toBe(3)
        ->and($rate['today'])->toBe(0)
        // Yesterday's check-ins count in the period, not today.
        ->and($rate['period'])->toBe(22.2);
});

test('attendance rate is null on a day no one is expected', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->travelTo(Carbon::parse('2026-10-18 12:00', 'Africa/Addis_Ababa'));
    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    expect(test()->getJson(metricFixUrl($tenant->subdomain))->assertOk()->json('attendance_rate.today'))->toBeNull();
});

test('a period must be real dates, in order, and at most 400 days long', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // Any text went to Carbon::parse: a malformed date was a 500.
    foreach (['?from=yesterday', '?from=2026-02-30', '?from=2026-10-31&to=2026-10-01', '?from=2025-01-01&to=2026-10-31'] as $query) {
        test()->getJson(metricFixUrl($tenant->subdomain, $query))->assertUnprocessable();
    }

    test()->getJson(metricFixUrl($tenant->subdomain, '/payroll?from=2025-11-01&to=2026-10-31'))->assertOk();
    test()->getJson(metricFixUrl($tenant->subdomain, '/attendance?to=2026-12-31'))->assertOk();
});

test('the attendance rate over a year counts each expected day once', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->travelTo(Carbon::parse('2026-10-14 12:00', 'Africa/Addis_Ababa'));
    $present = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-09-01']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-09-01']);

    foreach (Carbon::parse('2026-09-01')->daysUntil('2026-10-14') as $day) {
        if ($day->isWeekday()) {
            metricFixAttend($present, $day->toDateString());
        }
    }

    // Twelve months asked for, but no one was employed before September and
    // nothing after today counts: still half.
    expect(test()->getJson(metricFixUrl($tenant->subdomain, '?from=2025-11-01&to=2026-10-31'))->assertOk()->json('attendance_rate.period'))
        ->toBe(50);
});

test('a payroll run belongs to the month holding its middle day, in either calendar', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // A Gregorian October run, and a run cut on Ethiopian months (Meskerem:
    // 11 September to 10 October).
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'gross_total_cents' => 100000]);
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'approved', 'period_start' => '2026-09-11', 'period_end' => '2026-10-10', 'gross_total_cents' => 20000]);

    $gross = fn (string $from, string $to) => test()
        ->getJson(metricFixUrl($tenant->subdomain, "/payroll?from={$from}&to={$to}"))
        ->assertOk()
        ->json('totals.total_gross_cents');

    // Tikimt 2019 holds the October run; Gregorian September holds the
    // Meskerem run; Gregorian October only its own.
    expect($gross('2026-10-11', '2026-11-09'))->toBe(100000)
        ->and($gross('2026-09-01', '2026-09-30'))->toBe(20000)
        ->and($gross('2026-10-01', '2026-10-31'))->toBe(100000);
});

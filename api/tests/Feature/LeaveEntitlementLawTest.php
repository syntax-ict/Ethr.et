<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Onboarding\LeaveTypeCatalog;
use Carbon\Carbon;

// Labour Proclamation No. 1156/2019, Art. 77: sixteen working days for the
// first year of service, plus one for every additional two years, and leave
// proportional to service for someone who joins or leaves part-way through.
// Until 2026-10-20 every employee accrued 16 from January, whether hired in
// March, gone since February, or ten years in (blueprint assessment LV2, LV6).

afterEach(function () {
    Carbon::setTestNow();
});

function statutoryAnnual(int $tenantId): LeaveType
{
    return LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenantId,
        'code' => 'annual',
        'default_days' => 16,
        'seniority_every_years' => 2,
        'seniority_extra_days' => 1,
    ]);
}

function annualBalance(Employee $employee, LeaveType $type): float
{
    return (float) LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)
        ->where('year', 2026)
        ->value('entitled_days');
}

it('adds one day for every additional two years of service', function (string $hired, float $days) {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => $hired]);

    expect((new LeaveBalanceService)->yearlyEntitlement($employee, statutoryAnnual($tenant->id), Carbon::parse('2026-12-31')))
        ->toBe($days);
})->with([
    'first year' => ['2026-02-01', 16.0],
    'one year done' => ['2025-06-01', 16.0],
    'two years done' => ['2024-06-01', 17.0],
    'three years done' => ['2023-06-01', 17.0],
    'four years done' => ['2022-06-01', 18.0],
    'ten years done' => ['2016-06-01', 21.0],
]);

it('accrues a ten-year employee at the rate their service has reached', function () {
    $tenant = createTenant();
    $type = statutoryAnnual($tenant->id);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male', 'hire_date' => '2016-01-01']);

    Carbon::setTestNow('2026-06-15');
    (new LeaveBalanceService)->accrueMonthly($tenant->id);

    expect(annualBalance($employee, $type))->toBe(10.5); // 21 days x 6/12
});

it('accrues a mid-year hire only from the month they joined', function () {
    $tenant = createTenant();
    $type = statutoryAnnual($tenant->id);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male', 'hire_date' => '2026-03-10']);

    Carbon::setTestNow('2026-06-15');
    (new LeaveBalanceService)->accrueMonthly($tenant->id);

    expect(annualBalance($employee, $type))->toBe(5.3); // 16 x 4/12, March to June
});

it('stops accruing for someone who has left', function () {
    $tenant = createTenant();
    $type = statutoryAnnual($tenant->id);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'gender' => 'male', 'hire_date' => '2026-01-01',
        'status' => EmployeeStatus::RESIGNED, 'termination_date' => '2026-02-20',
    ]);
    $service = new LeaveBalanceService;

    Carbon::setTestNow('2026-03-01');
    $service->accrueMonthly($tenant->id);
    expect(annualBalance($employee, $type))->toBe(2.7); // 16 x 2/12

    Carbon::setTestNow('2026-09-01');
    $service->accrueMonthly($tenant->id);
    expect(annualBalance($employee, $type))->toBe(2.7);
});

it('accrues nothing for someone not yet hired', function () {
    $tenant = createTenant();
    statutoryAnnual($tenant->id);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male', 'hire_date' => '2026-11-01']);

    Carbon::setTestNow('2026-06-15');
    (new LeaveBalanceService)->accrueMonthly($tenant->id);

    expect(LeaveBalance::where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('gives a new organisation the statutory seniority step on annual leave', function () {
    expect(app(LeaveTypeCatalog::class)->resolve('annual'))
        ->toMatchArray(['seniority_every_years' => 2, 'seniority_extra_days' => 1]);
    expect(app(LeaveTypeCatalog::class)->resolve('sick'))
        ->toMatchArray(['seniority_every_years' => null, 'seniority_extra_days' => null]);
});

it('lets an organisation set and clear the step on a leave type', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $base = "http://{$tenant->subdomain}.ethr.test/api/v1/leave-types";

    $created = test()->postJson($base, [
        'name' => 'Long-service annual',
        'code' => 'annual_ls',
        'default_days' => 20,
        'accrual_type' => 'monthly',
        'seniority_every_years' => 1,
        'seniority_extra_days' => 1,
    ])->assertCreated()
        ->assertJsonPath('seniority_every_years', 1)
        ->assertJsonPath('seniority_extra_days', 1)
        ->json('public_id');

    test()->putJson("{$base}/{$created}", ['seniority_every_years' => null, 'seniority_extra_days' => null])
        ->assertOk()
        ->assertJsonPath('seniority_every_years', null);
});

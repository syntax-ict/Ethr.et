<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;

/**
 * Before HR saves a change to pay, the form asks which released months the
 * date reaches: the same set PayDifferenceRecorder would record a difference
 * for. A notice only; nothing is refused.
 */
function pmRun(Tenant $tenant, Employee $employee, string $status, string $start, string $end): PayrollRun
{
    $run = PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => $status,
        'period_start' => $start,
        'period_end' => $end,
    ]);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);

    return $run;
}

function pmUrl(Employee $employee, ?string $from): string
{
    return "/api/v1/employees/{$employee->public_id}/paid-months".($from === null ? '' : "?from={$from}");
}

it('counts the approved and paid months the date reaches, with the first and last day', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    pmRun($tenant, $employee, 'paid', '2026-09-01', '2026-09-30');
    pmRun($tenant, $employee, 'approved', '2026-10-01', '2026-10-31');

    $this->getJson(pmUrl($employee, '2026-09-15'))
        ->assertOk()
        ->assertExactJson(['data' => ['count' => 2, 'earliest_period_start' => '2026-09-01', 'latest_period_end' => '2026-10-31']]);
});

it('does not count a draft, completed or voided run', function (string $status) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    pmRun($tenant, $employee, $status, '2026-10-01', '2026-10-31');

    $this->getJson(pmUrl($employee, '2026-01-01'))
        ->assertOk()
        ->assertExactJson(['data' => ['count' => 0, 'earliest_period_start' => null, 'latest_period_end' => null]]);
})->with(['draft', 'completed', 'voided']);

it('does not count a month that ended before the date, and counts the month the date falls on the last day of', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    pmRun($tenant, $employee, 'paid', '2026-09-01', '2026-09-30');
    pmRun($tenant, $employee, 'paid', '2026-10-01', '2026-10-31');

    $this->getJson(pmUrl($employee, '2026-10-01'))->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.earliest_period_start', '2026-10-01');
    $this->getJson(pmUrl($employee, '2026-10-31'))->assertJsonPath('data.count', 1);
    $this->getJson(pmUrl($employee, '2026-11-01'))->assertJsonPath('data.count', 0);
});

it('does not count another employee in the same organisation', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $other = Employee::factory()->create(['tenant_id' => $tenant->id]);
    pmRun($tenant, $other, 'paid', '2026-10-01', '2026-10-31');

    $this->getJson(pmUrl($employee, '2026-01-01'))->assertJsonPath('data.count', 0);
});

it('answers 404 for another organisation\'s employee', function () {
    $mine = createTenant();
    $theirs = Tenant::factory()->create();
    $stranger = Employee::factory()->create(['tenant_id' => $theirs->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $mine);

    $this->getJson(pmUrl($stranger, '2026-01-01'))->assertNotFound();
});

it('is refused to someone who cannot view the employee', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $this->getJson(pmUrl($employee, '2026-01-01'))->assertForbidden();
});

it('wants a from date in Y-m-d', function (?string $from) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $this->getJson(pmUrl($employee, $from))->assertStatus(422)->assertJsonValidationErrors('from');
})->with([null, 'not-a-date', '2026-13-40', '15/09/2026', '2026-9-1']);

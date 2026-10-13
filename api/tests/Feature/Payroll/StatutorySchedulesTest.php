<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use App\Support\Csv;
use Carbon\Carbon;

/**
 * The monthly statutory schedules (2026-10-14, gap 4): income tax withheld for
 * the Ministry of Revenues, pension contributions for POESSA or PSSSA. Built
 * from the run register, so their totals are the run's own. The figures here
 * are the golden payslips' (PayrollGoldenPayslipsTest): 3,500 ETB → 225 tax,
 * 245 / 385 pension; 25,000 ETB → 6,700 tax, 1,750 / 2,750 pension.
 */
function statutoryRun(Tenant $tenant, int $userId): PayrollRun
{
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'employee_code' => 'G2', 'name' => 'Almaz Bekele',
        'salary_cents' => 350000, 'hire_date' => '2025-01-01', 'status' => 'confirmed', 'tin' => '0012345678',
    ]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id, 'employee_code' => 'G6', 'name' => 'Dawit Haile',
        'salary_cents' => 2500000, 'hire_date' => '2025-01-01', 'status' => 'confirmed', 'tin' => null,
    ]);

    return app(PayrollEngine::class)->process(
        $tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $userId,
    )->run;
}

function statutoryUrl(Tenant $tenant, PayrollRun $run, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/statutory/{$path}";
}

it('totals the tax withheld to the run, and names who has no TIN', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $tenant->update(['settings' => [...($tenant->settings ?? []), 'employer_tin' => '0098765432', 'tax_center' => 'Bole Medium Taxpayers']]);
    $run = statutoryRun($tenant, $user->id);

    test()->getJson(statutoryUrl($tenant, $run, 'income-tax'))
        ->assertOk()
        ->assertJsonPath('employees', 2)
        ->assertJsonPath('totals.income_tax_cents', 692500)
        ->assertJsonPath('totals.income_tax_cents', (int) $run->fresh()->tax_total_cents)
        ->assertJsonPath('header.employer_tin', '0098765432')
        ->assertJsonPath('header.tax_center', 'Bole Medium Taxpayers')
        ->assertJsonPath('header.approved', false)
        ->assertJsonPath('missing_tin', ['Dawit Haile'])
        ->assertJsonMissingPath('rows');
});

it('downloads the tax schedule with each TIN, says the layout is unconfirmed, and audits it', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = statutoryRun($tenant, $user->id);

    $csv = test()->get(statutoryUrl($tenant, $run, 'income-tax/download'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->getContent();

    expect($csv)
        ->toContain('Employment Income Tax Schedule')
        ->toContain('0012345678')
        ->toContain('Layout to be confirmed with the Ministry of Revenues')
        ->toContain('NOT SET')
        ->toContain(Csv::amount(692500))
        ->and(AuditLog::where('action', 'payroll.statutory_export')->count())->toBe(1);
});

it('lists both pension shares, under the scheme the organisation belongs to', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = statutoryRun($tenant, $user->id);

    test()->getJson(statutoryUrl($tenant, $run, 'pension'))
        ->assertOk()
        ->assertJsonPath('totals.employee_pension_cents', 199500)
        ->assertJsonPath('totals.employer_pension_cents', 313500)
        ->assertJsonPath('totals.total_pension_cents', 513000)
        ->assertJsonPath('header.scheme', "Private Organization Employees' Social Security Agency (POESSA)");

    $tenant->update(['settings' => [...($tenant->settings ?? []), 'pension_scheme' => 'public']]);

    expect(test()->get(statutoryUrl($tenant, $run, 'pension/download'))->getContent())
        ->toContain('PSSSA')
        ->toContain('Employer 11% (ETB)')
        ->toContain(Csv::amount(513000));
});

it('prints a PDF of either schedule', function (string $schedule) {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = statutoryRun($tenant, $user->id);

    $response = test()->get(statutoryUrl($tenant, $run, "{$schedule}/download?format=pdf"))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
})->with(['income-tax', 'pension']);

it('is closed to anyone who cannot see the payroll, and knows only its two schedules', function () {
    $tenant = createTenant();
    $finance = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = statutoryRun($tenant, $finance->id);

    test()->getJson(statutoryUrl($tenant, $run, 'vat'))->assertNotFound();

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    test()->getJson(statutoryUrl($tenant, $run, 'income-tax'))->assertForbidden();
    test()->get(statutoryUrl($tenant, $run, 'income-tax/download'))->assertForbidden();
});

it('keeps the employer TIN, tax centre and scheme in the organisation settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/settings";

    test()->putJson($url, ['settings' => ['employer_tin' => '123']])->assertUnprocessable();
    test()->putJson($url, ['settings' => ['pension_scheme' => 'military']])->assertUnprocessable();
    test()->putJson($url, ['settings' => ['employer_tin' => '0098765432', 'tax_center' => 'Bole', 'pension_scheme' => 'public']])->assertOk();

    test()->getJson($url)
        ->assertJsonPath('payroll.employer_tin', '0098765432')
        ->assertJsonPath('payroll.tax_center', 'Bole')
        ->assertJsonPath('payroll.pension_scheme', 'public');
});

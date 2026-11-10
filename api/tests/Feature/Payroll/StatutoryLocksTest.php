<?php

declare(strict_types=1);

use App\Enums\ContractType;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollEntry;
use App\Models\PensionRule;
use App\Models\TaxBracket;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\StatutoryRules;
use App\Services\Payroll\TaxCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Locked statutory values (2026-11-09, docs/payroll/STATUTORY-PARAMETERS.md,
 * decision D6): the income tax ladder and the pension rates are the law's
 * exactly, so an organisation can no longer save its own; one it saved
 * before is still honoured for its dates. Where the law sets a floor, an
 * organisation may give more through its own leave types, never less. And
 * pension covers the contract types `pension.applies_to` says it does.
 */

it('refuses a new tax ladder, citing the law', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = $this->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/tax-brackets", [
        'effective_from' => '2027-01-01',
        'brackets' => [['min_amount_cents' => 0, 'max_amount_cents' => null, 'rate' => 10, 'deduction_cents' => 0]],
    ])->assertUnprocessable();

    expect($response->json('detail'))->toContain('1395/2025');

    expect(TaxBracket::query()->count())->toBe(0);
});

it('refuses a new pension rule, citing the law', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/pension-rules", [
        'scheme' => 'private', 'employee_rate' => 5, 'employer_rate' => 5,
        'effective_from' => '2027-01-01', 'legal_reference' => 'Board resolution',
    ])->assertUnprocessable();

    expect($response->json('detail'))->toContain('1268/2022');

    expect(PensionRule::query()->count())->toBe(0);
});

it('still honours a ladder an organisation saved before ladders were locked', function () {
    $tenant = createTenant();
    TaxBracket::create(['min_amount_cents' => 0, 'max_amount_cents' => 0, 'rate' => 10, 'deduction_cents' => 0, 'effective_from' => '2026-01-01']);

    expect(app(TaxCalculator::class)->basis($tenant->id, Carbon::parse('2026-06-01'))['source'])->toBe('tenant')
        ->and(app(TaxCalculator::class)->calculate(1000000, $tenant->id, Carbon::parse('2026-06-01')))->toBe(100000);
});

it('taxes a type with no ladder of its own on its parent type\'s ladder', function () {
    $tenant = createTenant(['organization_type' => 'share_company']);
    DB::table('tax_brackets')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => null, 'organization_type' => 'private',
        'min_amount_cents' => 0, 'max_amount_cents' => 0, 'rate' => 12, 'deduction_cents' => 0,
        'effective_from' => '2026-01-01', 'legal_reference' => 'Test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(TaxCalculator::class)->basis($tenant->id, Carbon::parse('2026-06-01'))['source'])->toBe('platform_type')
        ->and(app(TaxCalculator::class)->calculate(1000000, $tenant->id, Carbon::parse('2026-06-01')))->toBe(120000);
});

it('refuses a statutory leave type below the law, and accepts one at or above it', function (string $code, int $below, int $at) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/leave-types";
    $type = ['name' => ucfirst($code), 'code' => $code, 'accrual_type' => 'annual', 'is_paid' => true];

    $this->postJson($url, [...$type, 'default_days' => $below])
        ->assertUnprocessable()->assertJsonValidationErrors('default_days');
    $created = $this->postJson($url, [...$type, 'default_days' => $at])->assertCreated();

    $this->putJson($url.'/'.$created->json('public_id'), ['default_days' => $below])
        ->assertUnprocessable()->assertJsonValidationErrors('default_days');
    $this->putJson($url.'/'.$created->json('public_id'), ['default_days' => $at + 4])->assertOk();
})->with([
    'annual' => ['annual', 15, 16],
    'maternity' => ['maternity', 90, 120],
    'paternity' => ['paternity', 2, 3],
]);

it('leaves a leave type the law sets no days for to the organisation', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types", [
        'name' => 'Study', 'code' => 'study', 'accrual_type' => 'annual', 'is_paid' => true, 'default_days' => 1,
    ])->assertCreated();
});

it('exempts a contract type pension.applies_to excludes, and only that one', function () {
    $tenant = createTenant();
    $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    DB::table('statutory_parameters')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => null, 'organization_type' => null, 'code' => 'pension.applies_to',
        'value' => json_encode(['permanent' => true, 'fixed_term' => true, 'probation' => true, 'casual' => false, 'consultancy' => false]),
        'enforcement' => 'locked', 'tenant_overridable' => false, 'legal_reference' => 'Test', 'confirmed' => false,
        'effective_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(StatutoryRules::class)->forget();

    $people = [];
    foreach (['casual' => ContractType::CASUAL, 'permanent' => ContractType::PERMANENT] as $code => $kind) {
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id, 'employee_code' => strtoupper($code), 'salary_cents' => 1000000, 'hire_date' => '2020-01-01', 'status' => 'confirmed',
        ]);
        EmployeeContract::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'contract_type' => $kind,
            'start_date' => '2020-01-01', 'end_date' => null, 'salary_cents' => 1000000,
        ]);
        $people[$code] = $employee;
    }

    $run = app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $finance->id)->run;
    $pension = fn (Employee $e) => collect(PayrollEntry::query()->where('payroll_run_id', $run->id)->where('employee_id', $e->id)->firstOrFail()->calculation_log['steps'])
        ->firstWhere('step', 'pension');

    expect($pension($people['casual']))->toMatchArray(['exempt' => true, 'exempt_reason' => 'contract_type'])
        ->and($pension($people['permanent'])['exempt'])->toBeFalse();
});

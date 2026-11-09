<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\LeaveType;
use App\Models\TaxBracket;
use App\Models\Tenant;
use App\Services\Onboarding\ReadinessScorer;

/*
 * "Ready for first payroll" (2026-11-09, Phase 2): computed now from what is
 * stored, never kept as flags. GET /next-steps reports how many of the
 * payroll checks pass, and each failing one is a step: the employer's TIN,
 * tax centre and pension registration, how salaries are paid, the law's own
 * tax ladder and pension rates, leave that grants what the law grants, and
 * a salary, TIN and bank account for everyone. Linking a device is
 * suggested, never required.
 */

function fpUrl(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/next-steps";
}

/** A tenant admin, and one employee with everything payroll needs. */
function fpReadyTenant(array $settings = []): Tenant
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'phone' => '+251911000000'], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1200000, 'tin' => '0012345678']);
    EmployeeBankDetail::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'bank_name' => 'Awash Bank',
        'account_number' => '1000123456789', 'is_primary' => true,
    ]);
    $tenant->update(['settings' => [...$tenant->settings, 'employer_tin' => '0098765432', 'tax_center' => 'Bole',
        'pension_registration_number' => 'P-0042', 'payout_method' => 'bank', 'employer_bank_name' => 'Commercial Bank of Ethiopia',
        ...$settings]]);

    return $tenant;
}

it('counts the payroll checks a bare organisation has not met, each one a step', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'phone' => '+251911000000'], $tenant);

    $response = $this->getJson(fpUrl($tenant))->assertOk();
    $steps = collect($response->json('steps'))->keyBy('id');

    expect($response->json('payroll'))->toMatchArray(['ready' => false])
        ->and($response->json('payroll.total'))->toBe(9)
        ->and($steps->keys()->all())->toContain('setup_employer_tin', 'setup_tax_center', 'setup_pension_registration', 'setup_payout_method')
        ->and($steps['setup_pension_registration']['href'])->toBe('/settings/payroll');
});

it('is ready once everything payroll needs is recorded', function () {
    $tenant = fpReadyTenant();

    $response = $this->getJson(fpUrl($tenant))->assertOk();

    expect($response->json('payroll'))->toBe(['done' => 9, 'total' => 9, 'ready' => true])
        ->and(collect($response->json('steps'))->pluck('id')->all())
        ->not->toContain('setup_employer_tin', 'setup_tax_center', 'setup_pension_registration', 'setup_payout_method', 'setup_employee_salaries');
});

it('needs the paying bank when salaries go by bank transfer', function () {
    $tenant = fpReadyTenant(['employer_bank_name' => null]);

    expect(collect($this->getJson(fpUrl($tenant))->json('steps'))->pluck('id')->all())->toContain('setup_payout_method');
});

it('blocks on a tax ladder the organisation saved before ladders were locked', function () {
    $tenant = fpReadyTenant();
    TaxBracket::create(['min_amount_cents' => 0, 'max_amount_cents' => 0, 'rate' => 5, 'deduction_cents' => 0, 'effective_from' => '2020-01-01']);

    $response = $this->getJson(fpUrl($tenant))->assertOk();
    $step = collect($response->json('steps'))->firstWhere('id', 'statutory_override');

    expect($step)->toMatchArray(['severity' => 'blocker', 'href' => '/settings/payroll?tab=rules'])
        ->and($response->json('payroll.ready'))->toBeFalse();
});

it('flags a leave type that grants fewer days than the law', function () {
    $tenant = fpReadyTenant();
    LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual', 'default_days' => 12]);

    expect(collect($this->getJson(fpUrl($tenant))->json('steps'))->pluck('id')->all())->toContain('leave_below_law');
});

it('suggests linking a device without lowering the score', function () {
    $tenant = fpReadyTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $before = app(ReadinessScorer::class)->score($tenant->id);

    $step = collect($this->getJson(fpUrl($tenant))->json('steps'))->firstWhere('id', 'setup_device_linked');
    expect($step)->toMatchArray(['severity' => 'info']);

    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'adapter_type' => 'zkteco']);
    $after = app(ReadinessScorer::class)->score($tenant->id);

    expect($after['overall_score'])->toBe($before['overall_score'])
        ->and(collect($this->getJson(fpUrl($tenant))->json('steps'))->pluck('id')->all())->not->toContain('setup_device_linked');
});

it('says when the accountant has not confirmed the organisation type\'s pension coverage', function (string $type, bool $shown) {
    $tenant = fpReadyTenant();
    $tenant->update(['organization_type' => $type]);

    expect(collect($this->getJson(fpUrl($tenant))->json('steps'))->pluck('id')->contains('profile_legal_review'))->toBe($shown);
})->with([
    'a sole proprietor' => ['sole_proprietor', true],
    'an embassy' => ['diplomatic_mission', true],
    'a share company' => ['share_company', false],
]);

it('reports nothing about payroll to someone who cannot set it up', function () {
    $tenant = fpReadyTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    expect($this->getJson(fpUrl($tenant))->assertOk()->json('payroll'))->toBeNull();
});

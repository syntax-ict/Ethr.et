<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\StatutoryParameter;
use App\Models\Tenant;

/*
 * Settings → Payroll → Statutory rules (2026-11-09, Phase 2): every rule the
 * organisation's payroll applies, with where it came from; the ones it may
 * change are changed through PUT /settings and reset here, which deletes its
 * own row. The law's rules are listed, never editable. And the payroll
 * details the first-payroll checklist asks for: the pension registration
 * number under the right agency, and how salaries are paid.
 */

function rulesUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function ruleNamed(array $rules, string $code): array
{
    return collect($rules)->firstWhere('code', $code) ?? throw new RuntimeException("no {$code}");
}

it('lists every rule with its provenance, the law\'s rules locked', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $response = $this->getJson(rulesUrl($tenant, '/payroll/statutory-rules'))->assertOk()
        ->assertJsonPath('organization_type', 'private');
    $rules = $response->json('data');

    expect(ruleNamed($rules, 'payroll.hours_per_day'))->toMatchArray([
        'value' => 8, 'enforcement' => 'locked', 'editable' => false, 'setting_key' => null, 'layer' => 'baseline', 'version' => '2026.11.1',
    ])->and(ruleNamed($rules, 'payroll.hours_per_day')['legal_reference'])->toContain('1156/2019')
        ->and(ruleNamed($rules, 'payroll.working_days_per_month'))->toMatchArray([
            'value' => 22, 'default_value' => 22, 'enforcement' => 'default', 'editable' => true,
            'setting_key' => 'working_days_per_month', 'overridden' => false, 'layer' => 'organization_type',
        ])
        ->and(ruleNamed($rules, 'leave.annual.base_days'))->toMatchArray(['value' => 16, 'floor_direction' => 'min', 'editable' => false]);
});

it('shows an organisation\'s own value beside the default, and resets it by deleting its row', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson(rulesUrl($tenant, '/settings'), ['settings' => ['working_days_per_month' => 26]])->assertOk();

    expect(ruleNamed($this->getJson(rulesUrl($tenant, '/payroll/statutory-rules'))->json('data'), 'payroll.working_days_per_month'))
        ->toMatchArray(['value' => 26, 'default_value' => 22, 'layer' => 'tenant', 'overridden' => true]);

    $this->deleteJson(rulesUrl($tenant, '/payroll/statutory-rules/payroll.working_days_per_month'))
        ->assertOk()->assertJson(['code' => 'payroll.working_days_per_month', 'value' => 22, 'layer' => 'organization_type']);

    expect(StatutoryParameter::query()->where('code', 'payroll.working_days_per_month')->count())->toBe(0)
        ->and($tenant->fresh()->settings)->not->toHaveKey('working_days_per_month')
        ->and(AuditLog::query()->where('action', 'statutory_parameter.reset')->value('payload'))
        ->toEqual(['code' => 'payroll.working_days_per_month', 'from' => 26]);
});

it('refuses to reset the law, and does not know a rule that is not in force', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->deleteJson(rulesUrl($tenant, '/payroll/statutory-rules/payroll.hours_per_day'))->assertUnprocessable();
    $this->deleteJson(rulesUrl($tenant, '/payroll/statutory-rules/nothing.like.this'))->assertNotFound();
});

it('lets only those who manage payroll configuration reset a rule, and only those who see it list them', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $this->deleteJson(rulesUrl($tenant, '/payroll/statutory-rules/pagume.proration'))->assertForbidden();

    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $this->getJson(rulesUrl($tenant, '/payroll/statutory-rules'))->assertForbidden();
});

it('resets only the caller\'s own organisation', function () {
    $other = createTenant();
    $other->update(['settings' => [...$other->settings, 'working_days_per_month' => 24]]);
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->deleteJson(rulesUrl($tenant, '/payroll/statutory-rules/payroll.working_days_per_month'))->assertOk();

    expect($other->fresh()->settings['working_days_per_month'])->toBe(24)
        ->and(StatutoryParameter::query()->withoutGlobalScope('tenant')->where('tenant_id', $other->id)->count())->toBe(1);
});

it('names the pension agency for the organisation\'s scheme, and keeps its registration number and payout', function (string $type, string $agency) {
    $tenant = createTenant(['organization_type' => $type]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson(rulesUrl($tenant, '/settings'), ['settings' => [
        'pension_registration_number' => 'P-0042', 'payout_method' => 'bank', 'employer_bank_name' => 'Commercial Bank of Ethiopia',
    ]])->assertOk();

    $this->getJson(rulesUrl($tenant, '/settings'))->assertOk()
        ->assertJsonPath('payroll.pension_registration_number', 'P-0042')
        ->assertJsonPath('payroll.payout_method', 'bank')
        ->assertJsonPath('payroll.employer_bank_name', 'Commercial Bank of Ethiopia')
        ->assertJsonPath('payroll.pension_agency', fn (string $name) => str_contains($name, $agency));
})->with([
    'a company' => ['private', 'POESSA'],
    'a ministry' => ['government', 'PSSSA'],
]);

it('accepts only the payout methods and pay frequency payroll can carry out', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson(rulesUrl($tenant, '/settings'), ['settings' => ['payout_method' => 'telebirr']])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.payout_method');
    $this->putJson(rulesUrl($tenant, '/settings'), ['settings' => ['pay_period' => 'weekly']])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.pay_period');
    $this->putJson(rulesUrl($tenant, '/settings'), ['settings' => ['pay_period' => 'monthly', 'payout_method' => 'cash']])->assertOk();
});

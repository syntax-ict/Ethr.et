<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\PayrollRun;
use App\Models\TenantProfileAssignment;
use App\Services\Payroll\StatutoryRules;
use Illuminate\Support\Carbon;

/**
 * A change of the kind of organisation once payroll has been approved
 * (2026-11-09, decision D11): approved pay was worked out under the old type,
 * so the new one applies from a future organisation-month start after the
 * last approved period, and earlier periods keep resolving to the old type.
 * Before anything is approved the change is a correction of history.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-09 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function typeChangeUrl(object $tenant): string
{
    return 'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization';
}

function approvedRunFor(object $tenant, string $end = '2026-10-31'): PayrollRun
{
    return PayrollRun::factory()->create([
        'tenant_id' => $tenant->id,
        'period_start' => Carbon::parse($end)->startOfMonth()->toDateString(),
        'period_end' => $end,
        'status' => 'approved',
    ]);
}

it('corrects the history while no payroll is approved', function () {
    $tenant = createTenant(['organization_type' => 'private']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'ngo'])
        ->assertOk()
        ->assertJsonPath('organization.organization_type', 'ngo')
        ->assertJsonPath('organization.organization_type_pending', null)
        ->assertJsonPath('organization.organization_type_earliest_change', null);

    expect(TenantProfileAssignment::query()->where('tenant_id', $tenant->id)->pluck('organization_type')->all())
        ->toBe(['ngo']);
});

it('asks for a date once payroll has been approved', function () {
    $tenant = createTenant(['organization_type' => 'private', 'settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    approvedRunFor($tenant);

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'ngo'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['effective_from'])
        ->assertJsonFragment(['effective_from' => [__('validation.organization_type_change_future', ['date' => '2026-12-01'])]]);

    expect($tenant->fresh()->organization_type)->toBe('private');
});

it('refuses a date inside or before an approved period', function () {
    $tenant = createTenant(['organization_type' => 'private', 'settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    approvedRunFor($tenant, '2026-12-31');

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'ngo', 'effective_from' => '2026-12-01'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['effective_from']);
});

it('refuses a date that is not the first day of a month', function () {
    $tenant = createTenant(['organization_type' => 'private', 'settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    approvedRunFor($tenant);

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'ngo', 'effective_from' => '2026-12-15'])
        ->assertUnprocessable()
        ->assertJsonFragment(['effective_from' => [__('validation.organization_type_change_month_start')]]);
});

it('counts months in the Ethiopian calendar when the organisation does', function () {
    $tenant = createTenant(['organization_type' => 'private']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    approvedRunFor($tenant);

    // Hidar 1, 2019 is 10 November 2026; 1 December is Hidar 22.
    test()->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('organization.organization_type_earliest_change', '2026-11-10');

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'ngo', 'effective_from' => '2026-12-01'])
        ->assertUnprocessable();
    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'ngo', 'effective_from' => '2026-11-10'])
        ->assertOk();
});

it('schedules the change and leaves approved periods under the old type', function () {
    $tenant = createTenant(['organization_type' => 'private', 'settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    approvedRunFor($tenant);

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'government', 'effective_from' => '2027-01-01'])
        ->assertOk()
        ->assertJsonPath('organization.organization_type', 'private')
        ->assertJsonPath('organization.organization_type_pending.organization_type', 'government')
        ->assertJsonPath('organization.organization_type_pending.effective_from', '2027-01-01');

    $rules = app(StatutoryRules::class);
    $rules->forget();
    $tenant = $tenant->fresh();

    expect($tenant->organization_type)->toBe('private')
        ->and($rules->for($tenant, Carbon::parse('2026-10-15'))->organizationType)->toBe('private')
        ->and($rules->for($tenant, Carbon::parse('2026-12-31'))->organizationType)->toBe('private')
        ->and($rules->for($tenant, Carbon::parse('2027-01-01'))->organizationType)->toBe('government')
        ->and($rules->for($tenant, Carbon::parse('2027-01-01'))->int('fiscal_year.start_month'))->toBe(11);
});

it('replaces a change still pending with the newer one', function () {
    $tenant = createTenant(['organization_type' => 'private', 'settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    approvedRunFor($tenant);

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'government', 'effective_from' => '2027-01-01'])->assertOk();
    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'ngo', 'effective_from' => '2027-02-01'])
        ->assertOk()
        ->assertJsonPath('organization.organization_type_pending.organization_type', 'ngo')
        ->assertJsonPath('organization.organization_type_pending.effective_from', '2027-02-01');

    expect(TenantProfileAssignment::query()->where('tenant_id', $tenant->id)->whereDate('effective_from', '>', '2026-11-09')->count())
        ->toBe(1);
});

it('cancels a pending change when the current type is chosen again', function () {
    $tenant = createTenant(['organization_type' => 'private', 'settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    approvedRunFor($tenant);

    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'government', 'effective_from' => '2027-01-01'])->assertOk();
    test()->putJson(typeChangeUrl($tenant), ['organization_type' => 'private'])
        ->assertOk()
        ->assertJsonPath('organization.organization_type_pending', null);
});

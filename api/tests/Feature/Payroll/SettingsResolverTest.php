<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\OrganizationType;
use App\Models\StatutoryParameter;
use App\Models\Tenant;
use App\Services\Payroll\StatutoryRules;
use App\Services\Tenancy\OrganisationProfile;
use App\Services\Tenancy\ProfileContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The layered settings resolver (2026-11-09, docs/payroll/STATUTORY-PARAMETERS.md):
 * baseline, the organisation type's parent, its type, its sector, then the
 * organisation's own row unless the law's row is locked. Effective-dated on
 * both the rows and the organisation's profile; every value says where it
 * came from; publishing a new batch of the law never touches an
 * organisation's own rows.
 */

function layerRow(string $code, mixed $value, ?string $type = null, ?string $sector = null, string $enforcement = 'default', string $from = '2026-01-01', ?int $tenantId = null): void
{
    DB::table('statutory_parameters')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'organization_type' => $type, 'sector' => $sector,
        'code' => $code, 'value' => json_encode($value), 'enforcement' => $enforcement,
        'tenant_overridable' => $enforcement !== 'locked', 'legal_reference' => 'Test', 'confirmed' => false,
        'effective_from' => $from, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function layerTenant(string $type, ?string $sector = null): Tenant
{
    $tenant = createTenant(['organization_type' => $type, 'settings' => ['leave_year' => 'gregorian', 'industry' => $sector]]);
    app(StatutoryRules::class)->forget();

    return $tenant;
}

function resolvedOn(Tenant $tenant, string $code, string $on = '2026-06-01'): ?array
{
    return app(StatutoryRules::class)->for($tenant, Carbon::parse($on))->describe($code);
}

it('takes the most specific layer: tenant, sector, type, parent type, baseline', function () {
    $tenant = layerTenant('private_limited_company', 'banking');

    layerRow('test.layered', 1);
    expect(resolvedOn($tenant, 'test.layered'))->toMatchArray(['value' => 1, 'layer' => 'baseline', 'source' => 'platform']);

    layerRow('test.layered', 2, type: 'private');
    app(StatutoryRules::class)->forget();
    expect(resolvedOn($tenant, 'test.layered'))->toMatchArray(['value' => 2, 'layer' => 'parent_type', 'source' => 'platform_type']);

    layerRow('test.layered', 3, type: 'private_limited_company');
    app(StatutoryRules::class)->forget();
    expect(resolvedOn($tenant, 'test.layered'))->toMatchArray(['value' => 3, 'layer' => 'organization_type']);

    layerRow('test.layered', 4, sector: 'banking');
    app(StatutoryRules::class)->forget();
    expect(resolvedOn($tenant, 'test.layered'))->toMatchArray(['value' => 4, 'layer' => 'sector', 'source' => 'platform_sector']);

    layerRow('test.layered', 5, tenantId: $tenant->id);
    app(StatutoryRules::class)->forget();
    expect(resolvedOn($tenant, 'test.layered'))->toMatchArray(['value' => 5, 'layer' => 'tenant', 'source' => 'tenant', 'enforcement' => 'default']);
});

it('ignores a sector row for another sector', function () {
    $tenant = layerTenant('private', 'hotel');
    layerRow('test.sector', 1);
    layerRow('test.sector', 9, sector: 'banking');

    expect(resolvedOn($tenant, 'test.sector'))->toMatchArray(['value' => 1, 'layer' => 'baseline']);
});

it('never lets an organisation replace a locked value', function () {
    $tenant = layerTenant('private');
    layerRow('test.locked', 10, enforcement: 'locked');
    layerRow('test.locked', 1, tenantId: $tenant->id);

    expect(resolvedOn($tenant, 'test.locked'))->toMatchArray(['value' => 10, 'layer' => 'baseline', 'enforcement' => 'locked']);
});

it('lets an organisation replace a default or advisory value', function (string $enforcement) {
    $tenant = layerTenant('private');
    layerRow('test.open', 10, enforcement: $enforcement);
    layerRow('test.open', 12, tenantId: $tenant->id);

    expect(resolvedOn($tenant, 'test.open'))->toMatchArray(['value' => 12, 'layer' => 'tenant', 'enforcement' => $enforcement]);
})->with(['default', 'advisory']);

it('resolves a past date against the rows and the organisation type in force then', function () {
    $tenant = layerTenant('private');
    $profile = app(OrganisationProfile::class);
    $profile->assign($tenant, 'private', null, Carbon::parse('2026-01-01'));
    $profile->assign($tenant, 'government', null, Carbon::parse('2026-07-01'));

    layerRow('test.dated', 'private-rule', type: 'private');
    layerRow('test.dated', 'government-rule', type: 'government');
    layerRow('test.dated', 'old-law', from: '2016-07-08');
    layerRow('test.dated', 'new-law', from: '2026-10-01');

    expect(resolvedOn($tenant, 'test.dated', '2026-03-01')['value'])->toBe('private-rule')
        ->and(resolvedOn($tenant, 'test.dated', '2026-08-01')['value'])->toBe('government-rule')
        // Before the first assignment: the type the organisation started with.
        ->and(resolvedOn($tenant, 'test.dated', '2025-06-01')['value'])->toBe('old-law');

    $rules = app(StatutoryRules::class);
    expect($rules->for($tenant, Carbon::parse('2025-06-01'))->organizationType)->toBe('private')
        ->and($rules->for($tenant, Carbon::parse('2026-08-01'))->organizationType)->toBe('government');
});

it('keeps tenants.organization_type equal to the assignment in force today, and audits each assignment', function () {
    $tenant = layerTenant('private');

    app(OrganisationProfile::class)->assign($tenant, 'ngo', 'education', Carbon::today());

    expect($tenant->fresh()->organization_type)->toBe('ngo')
        ->and(app(ProfileContext::class)->for($tenant->id, Carbon::today()))->toMatchArray(['organization_type' => 'ngo', 'sector' => 'education', 'lineage' => ['ngo']])
        ->and(AuditLog::query()->where('action', 'organization_profile.assigned')->exists())->toBeTrue();

    app(OrganisationProfile::class)->assign($tenant, 'government', null, Carbon::today()->addMonth());

    expect($tenant->fresh()->organization_type)->toBe('ngo');
});

it('leaves every organisation\'s own rows untouched when a new batch of the law is published', function () {
    $tenant = layerTenant('private');
    $tenant->update(['settings' => [...$tenant->settings, 'working_days_per_month' => 24]]);

    $before = DB::table('statutory_parameters')->whereNotNull('tenant_id')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    expect($before)->not->toBeEmpty();

    $version = DB::table('profile_versions')->insertGetId([
        'public_id' => (string) Str::ulid(), 'version' => '2027.1.1', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('statutory_parameters')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => null, 'organization_type' => null, 'code' => 'payroll.working_days_per_month',
        'value' => json_encode(21), 'enforcement' => 'default', 'tenant_overridable' => true, 'legal_reference' => 'Test',
        'confirmed' => false, 'profile_version_id' => $version, 'effective_from' => '2027-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(StatutoryRules::class)->forget();

    $after = DB::table('statutory_parameters')->whereNotNull('tenant_id')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    expect($after)->toBe($before)
        ->and(resolvedOn($tenant, 'payroll.working_days_per_month', '2027-02-01'))->toMatchArray(['value' => 24, 'layer' => 'tenant']);
});

it('reports the published batch each value came from', function () {
    $tenant = layerTenant('private');

    expect(resolvedOn($tenant, 'payroll.hours_per_day')['version'])->toBe('2026.11.1')
        ->and(resolvedOn($tenant, 'leave.annual.base_days')['version'])->toBe('2026.11.2');
});

it('keeps one organisation\'s override away from another', function () {
    $mine = layerTenant('private');
    $mine->update(['settings' => [...$mine->settings, 'working_days_per_month' => 26]]);
    $theirs = layerTenant('private');

    expect(resolvedOn($mine, 'payroll.working_days_per_month')['value'])->toBe(26)
        ->and(resolvedOn($theirs, 'payroll.working_days_per_month')['value'])->toBe(22)
        ->and(resolvedOn($theirs, 'payroll.working_days_per_month')['layer'])->toBe('organization_type');
});

it('pays every new organisation type exactly as its parent until a rule differs', function () {
    $children = OrganizationType::query()->whereNotNull('parent_code')->get(['code', 'parent_code']);
    expect($children)->toHaveCount(10);

    foreach ($children as $child) {
        expect(OrganizationType::query()->where('code', $child->parent_code)->value('parent_code'))->toBeNull();

        $values = fn (Tenant $t) => array_map(fn (array $rule) => $rule['value'], app(StatutoryRules::class)->for($t, Carbon::parse('2026-06-01'))->all());

        expect($values(layerTenant($child->code)))->toBe($values(layerTenant($child->parent_code)));
    }
});

it('keeps tenant_overridable equal to enforcement != locked on every row', function () {
    layerTenant('private')->update(['settings' => ['working_days_per_month' => 25]]);

    $disagree = StatutoryParameter::query()->withoutGlobalScope('tenant')->get()
        ->filter(fn (StatutoryParameter $row) => $row->tenant_overridable !== ($row->enforcement !== StatutoryParameter::LOCKED));

    expect($disagree)->toBeEmpty()
        ->and(StatutoryParameter::query()->withoutGlobalScope('tenant')->pluck('enforcement')->unique()->diff(StatutoryParameter::ENFORCEMENTS)->all())->toBe([]);
});

it('writes a legacy settings key through to the organisation\'s row, and removing it removes the row', function () {
    $tenant = layerTenant('private');

    $tenant->update(['settings' => [...$tenant->settings, 'pagumen_proration_strategy' => 'daily_rate']]);
    expect(resolvedOn($tenant, 'pagume.proration'))->toMatchArray(['value' => 'daily_rate', 'layer' => 'tenant']);

    $settings = $tenant->settings;
    unset($settings['pagumen_proration_strategy']);
    $tenant->update(['settings' => $settings]);
    app(StatutoryRules::class)->forget();

    expect(resolvedOn($tenant, 'pagume.proration'))->toMatchArray(['value' => 'full_month', 'layer' => 'baseline']);
});

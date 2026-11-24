<?php

declare(strict_types=1);

use App\Enums\EmploymentFramework;
use App\Enums\UserRole;
use App\Exceptions\PayrollRunRefused;
use App\Models\StatutoryParameter;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Statutory rules carry a domain. Payroll reads `payroll`; the employment
 * domain holds HR rules, starting with the framework each organisation kind
 * is governed by. Payroll, its calculation log and the Statutory rules page
 * must never see an employment rule, and a missing one answers null.
 */
function frameworkOf(string $type, string $on = '2026-06-01'): ?EmploymentFramework
{
    $tenant = createTenant(['organization_type' => $type]);

    return app(StatutoryRules::class)->framework($tenant, Carbon::parse($on));
}

function employmentRowFor(?int $tenantId, ?string $type, string $value): void
{
    DB::table('statutory_parameters')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'organization_type' => $type,
        'code' => 'employment.framework', 'domain' => 'employment', 'value' => json_encode($value),
        'tenant_overridable' => true, 'enforcement' => 'advisory', 'legal_reference' => 'Test', 'confirmed' => false,
        'effective_from' => '2019-09-05', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('resolves the framework seeded for each organisation kind', function (string $type, EmploymentFramework $expected) {
    expect(frameworkOf($type))->toBe($expected);
})->with([
    'private' => ['private', EmploymentFramework::LABOUR_PROCLAMATION],
    'ngo' => ['ngo', EmploymentFramework::LABOUR_PROCLAMATION],
    'public enterprise' => ['public_enterprise', EmploymentFramework::LABOUR_PROCLAMATION],
    'government' => ['government', EmploymentFramework::FEDERAL_CIVIL_SERVICE],
    'regional government' => ['regional_government', EmploymentFramework::REGIONAL_CIVIL_SERVICE],
    'defence and police' => ['defence_police', EmploymentFramework::UNSPECIFIED],
    'international organisation' => ['international_organization', EmploymentFramework::UNSPECIFIED],
]);

it('lets a child kind inherit its parent, except where it has its own row', function () {
    expect(frameworkOf('private_limited_company'))->toBe(EmploymentFramework::LABOUR_PROCLAMATION)
        ->and(frameworkOf('diplomatic_mission'))->toBe(EmploymentFramework::UNSPECIFIED)
        ->and(frameworkOf('regional_government'))->toBe(EmploymentFramework::REGIONAL_CIVIL_SERVICE)
        ->and(frameworkOf('regional_government'))->not->toBe(frameworkOf('government'));
});

it('answers null before a row takes effect', function () {
    expect(frameworkOf('government', '2024-06-01'))->toBeNull();
});

it('answers null, not a default, when the row for a kind is gone', function () {
    $tenant = createTenant(['organization_type' => 'ngo']);
    $rules = app(StatutoryRules::class);

    expect($rules->framework($tenant, Carbon::parse('2026-06-01')))->toBe(EmploymentFramework::LABOUR_PROCLAMATION);

    DB::table('statutory_parameters')->where('code', 'employment.framework')->whereNull('tenant_id')->where('organization_type', 'ngo')->delete();
    $rules->forget();

    expect($rules->framework($tenant, Carbon::parse('2026-06-01')))->toBeNull();
});

it('lets an organisation\'s own advisory row win over the platform row', function () {
    $tenant = createTenant(['organization_type' => 'private']);
    employmentRowFor($tenant->id, null, 'unspecified');
    $rules = app(StatutoryRules::class);
    $rules->forget();

    expect($rules->framework($tenant, Carbon::parse('2026-06-01')))->toBe(EmploymentFramework::UNSPECIFIED)
        ->and($rules->employment($tenant, Carbon::parse('2026-06-01'))->describe('employment.framework')['layer'])->toBe('tenant');
});

it('keeps employment rules out of the payroll set and its calculation log', function () {
    $tenant = createTenant(['organization_type' => 'private']);
    $rules = app(StatutoryRules::class);
    $on = Carbon::parse('2026-06-01');

    $withRows = $rules->for($tenant, $on)->toLog();
    expect(array_keys($withRows['rules']))->each->not->toStartWith('employment.');

    DB::table('statutory_parameters')->where('domain', 'employment')->delete();
    $rules->forget();

    expect($rules->for($tenant, $on)->toLog())->toEqual($withRows);
});

it('does not list an employment rule on Settings, Payroll, Statutory rules', function () {
    $tenant = createTenant(['organization_type' => 'private']);
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $codes = collect($this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/statutory-rules")->assertOk()->json('data'))->pluck('code');

    expect($codes)->not->toBeEmpty()
        ->and($codes->filter(fn ($code) => str_starts_with((string) $code, 'employment.')))->toBeEmpty();
});

it('seeds only known framework values', function () {
    $rows = DB::table('statutory_parameters')->where('domain', 'employment')->where('code', 'employment.framework')->get();

    expect($rows)->toHaveCount(7)
        ->and($rows->every(fn ($row) => EmploymentFramework::tryFrom(json_decode((string) $row->value, true)) !== null))->toBeTrue();
});

it('keeps every employment row advisory and unconfirmed, and the prefix and the domain in step', function () {
    $employment = DB::table('statutory_parameters')->where('domain', 'employment')->get();
    $payroll = DB::table('statutory_parameters')->where('domain', 'payroll')->pluck('code');

    expect($employment)->not->toBeEmpty()
        ->and($employment->every(fn ($row) => (int) $row->confirmed === 0 && $row->enforcement === 'advisory'))->toBeTrue()
        ->and($employment->every(fn ($row) => str_starts_with((string) $row->code, 'employment.')))->toBeTrue()
        ->and($payroll->filter(fn ($code) => str_starts_with((string) $code, 'employment.')))->toBeEmpty();
});

it('only the frameworks whose text was read have statutory rules', function () {
    expect(collect(EmploymentFramework::cases())->filter->hasStatutoryRules()->map->value->values()->all())
        ->toBe(['labour_proclamation', 'federal_civil_service']);
});

it('derives the domain from the code', function () {
    expect(StatutoryParameter::domainOf('employment.absence.grounds'))->toBe(StatutoryParameter::DOMAIN_EMPLOYMENT)
        ->and(StatutoryParameter::domainOf('payroll.hours_per_day'))->toBe(StatutoryParameter::DOMAIN_PAYROLL)
        ->and(StatutoryParameter::domainOf('leave.annual.base_days'))->toBe(StatutoryParameter::DOMAIN_PAYROLL);
});

it('stores a rule saved without a domain under the domain its code implies, and respects an explicit one', function () {
    $tenant = createTenant();
    $attributes = ['tenant_id' => $tenant->id, 'value' => 1, 'effective_from' => '2026-01-01'];

    $hr = StatutoryParameter::query()->create(['code' => 'employment.test_rule', ...$attributes]);
    $pay = StatutoryParameter::query()->create(['code' => 'payroll.test_rule', ...$attributes]);
    $explicit = StatutoryParameter::query()->create(['code' => 'payroll.other_rule', 'domain' => 'employment', ...$attributes]);

    expect(DB::table('statutory_parameters')->where('id', $hr->id)->value('domain'))->toBe('employment')
        ->and(DB::table('statutory_parameters')->where('id', $pay->id)->value('domain'))->toBe('payroll')
        ->and(DB::table('statutory_parameters')->where('id', $explicit->id)->value('domain'))->toBe('employment');
});

it('keeps the two domains apart on one resolver instance without forget()', function () {
    $tenant = createTenant(['organization_type' => 'private']);
    $rules = app(StatutoryRules::class);
    $on = Carbon::parse('2026-06-01');

    $payroll = $rules->for($tenant, $on);
    $employment = $rules->employment($tenant, $on);
    $payrollAgain = $rules->for($tenant, $on);

    expect($payroll->has('employment.framework'))->toBeFalse()
        ->and($payroll->has('payroll.hours_per_day'))->toBeTrue()
        ->and($employment->has('employment.framework'))->toBeTrue()
        ->and($employment->has('payroll.hours_per_day'))->toBeFalse()
        ->and($employment->all())->not->toEqual($payroll->all())
        ->and($payrollAgain->all())->toEqual($payroll->all());
});

it('finds a rule or answers null, while get() on a missing code still refuses', function () {
    $tenant = createTenant(['organization_type' => 'private']);
    $employment = app(StatutoryRules::class)->employment($tenant, Carbon::parse('2026-06-01'));

    expect($employment->find('employment.framework'))->toBe('labour_proclamation')
        ->and($employment->find('employment.absence.grounds'))->toBeNull();

    expect(fn () => $employment->get('employment.absence.grounds'))->toThrow(PayrollRunRefused::class, 'employment.absence.grounds');
});

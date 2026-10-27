<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\PayrollRunRefused;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\StatutoryParameter;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Statutory parameters as data, scoped by organisation type (2026-10-27,
 * docs/payroll/STATUTORY-PARAMETERS.md). The engine's constants became rows;
 * these tests pin that the rows say what the constants said, that a type's
 * row beats the row for every type, that an organisation overrides only what
 * the platform allows, and that a missing rule refuses a run by name.
 */
function parameterRun(Tenant $tenant, int $userId, string $from = '2026-10-01', string $to = '2026-10-31'): PayrollEntry
{
    $run = app(PayrollEngine::class)->process($tenant->id, Carbon::parse($from), Carbon::parse($to), $userId)->run;

    return PayrollEntry::query()->where('payroll_run_id', $run->id)->firstOrFail();
}

function parameterEmployee(Tenant $tenant, int $basic = 1000000): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => $basic, 'hire_date' => '2020-01-01', 'status' => 'confirmed',
    ]);
}

function platformParameter(string $code, mixed $value, ?string $type = null, string $from = '2026-01-01', bool $overridable = false): void
{
    DB::table('statutory_parameters')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => null, 'organization_type' => $type, 'code' => $code,
        'value' => json_encode($value), 'tenant_overridable' => $overridable, 'legal_reference' => 'Test', 'confirmed' => false,
        'effective_from' => $from, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('seeds exactly the values the engine used to carry as constants', function () {
    $rules = app(StatutoryRules::class)->platform(Carbon::parse('2026-10-01'));

    expect($rules->get('payroll.working_days_per_month'))->toBe(22)
        ->and($rules->get('payroll.hours_per_day'))->toBe(8)
        ->and($rules->get('overtime.minimum_rates'))->toEqual(['normal' => 1.5, 'night' => 1.75, 'rest_day' => 2.0, 'holiday' => 2.5, 'holiday_night' => 2.5])
        ->and($rules->get('overtime.night_window'))->toBe(['start_hour' => 22, 'end_hour' => 6])
        ->and($rules->get('overtime.legal_daily_minutes'))->toBe(240)
        ->and($rules->get('overtime.legal_weekly_minutes'))->toBe(720)
        ->and($rules->get('overtime.limited_types'))->toBe(['normal', 'night'])
        ->and($rules->get('pension.schemes'))->toBe(['private', 'public', 'public_military'])
        ->and($rules->get('pension.default_scheme'))->toBe('private')
        ->and($rules->get('fiscal_year.start_month'))->toBe(1)
        ->and($rules->get('pagume.proration'))->toBe('full_month');
});

it('keeps both tax ladders as rows, so a pre-amendment month still taxes under 979/2016', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    parameterEmployee($tenant, 200000);

    // 2,000 ETB: exempt under 1395/2025, 157.50 under 979/2016.
    expect(parameterRun($tenant, $user->id, '2025-06-01', '2025-06-30')->income_tax_cents)->toBe(15750);
});

it('resolves by organisation type: the government civil service gets its own scheme and fiscal year', function () {
    $company = createTenant(['organization_type' => 'private']);
    $ministry = createTenant(['organization_type' => 'government']);
    $army = createTenant(['organization_type' => 'defence_police']);
    $on = Carbon::parse('2026-10-01');
    $rules = app(StatutoryRules::class);

    expect($rules->for($company, $on)->get('pension.default_scheme'))->toBe('private')
        ->and($rules->for($company, $on)->get('fiscal_year.start_month'))->toBe(1)
        ->and($rules->for($ministry, $on)->get('pension.default_scheme'))->toBe('public')
        ->and($rules->for($ministry, $on)->get('fiscal_year.start_month'))->toBe(11)
        ->and($rules->for($ministry, $on)->describe('fiscal_year.start_month')['source'])->toBe('platform_type')
        ->and($rules->for($army, $on)->get('pension.default_scheme'))->toBe('public_military');
});

it('pays the same employee under each organisation type\'s rules, and logs where every rule came from', function () {
    $company = createTenant(['organization_type' => 'private']);
    $companyUser = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $company);
    parameterEmployee($company);
    $companyEntry = parameterRun($company, $companyUser->id);

    $army = createTenant(['organization_type' => 'defence_police']);
    $armyUser = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $army);
    parameterEmployee($army);
    $armyEntry = parameterRun($army, $armyUser->id);

    expect($companyEntry->employer_pension_cents)->toBe(110000)
        ->and($armyEntry->employer_pension_cents)->toBe(330000)
        ->and($companyEntry->income_tax_cents)->toBe($armyEntry->income_tax_cents);

    $log = $armyEntry->calculation_log;
    expect($log['version'])->toBe('1.5')
        ->and($log['statutory_rules']['organization_type'])->toBe('defence_police')
        ->and($log['statutory_rules']['rules']['pension.default_scheme'])->toMatchArray(['value' => 'public_military', 'source' => 'platform_type'])
        ->and($log['statutory_rules']['rules']['payroll.hours_per_day'])->toMatchArray(['value' => 8, 'source' => 'platform', 'confirmed' => true]);
});

it('lets an organisation override only what the platform marks overridable', function () {
    $tenant = createTenant(['settings' => ['fiscal_year_start_month' => 5]]);
    StatutoryParameter::query()->create([
        'tenant_id' => $tenant->id, 'code' => 'payroll.hours_per_day', 'value' => 6,
        'effective_from' => '2026-01-01', 'legal_reference' => 'Our own', 'confirmed' => false,
    ]);
    $rules = app(StatutoryRules::class)->for($tenant, Carbon::parse('2026-10-01'));

    expect($rules->get('fiscal_year.start_month'))->toBe(5)
        ->and($rules->describe('fiscal_year.start_month')['source'])->toBe('tenant_setting')
        ->and($rules->get('payroll.hours_per_day'))->toBe(8);
});

it('applies a new parameter row from its date, and only from its date', function () {
    platformParameter('payroll.working_days_per_month', 26, from: '2026-11-01');
    $tenant = createTenant();
    $rules = app(StatutoryRules::class);

    expect($rules->for($tenant, Carbon::parse('2026-10-31'))->get('payroll.working_days_per_month'))->toBe(22)
        ->and($rules->for($tenant, Carbon::parse('2026-11-01'))->get('payroll.working_days_per_month'))->toBe(26);
});

it('refuses a run when a rule it needs has no row in force, naming the rule', function () {
    DB::table('statutory_parameters')->where('code', 'payroll.hours_per_day')->delete();
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    parameterEmployee($tenant);

    expect(fn () => parameterRun($tenant, $user->id))->toThrow(PayrollRunRefused::class, 'payroll.hours_per_day');
    expect(PayrollEntry::query()->count())->toBe(0);
});

it('carries no statutory number in the payroll services', function () {
    $offenders = [];

    foreach (glob(app_path('Services/Payroll/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        // A constant assigned a number, or an array constant holding one.
        if (preg_match('/const\s+\w+\s*=\s*-?\d/', $source) === 1
            || preg_match('/const\s+\w+\s*=\s*\[[^;]*=>\s*-?\d[^;]*\];/s', $source) === 1) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

describe('organisation type', function () {
    it('lists the active types publicly, for the registration form', function () {
        $this->getJson('/api/v1/organization-types')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.code', 'private')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonStructure(['data' => [['code', 'name', 'name_am', 'description']]]);
    });

    it('is confirmed or changed by the organisation, and the change is audited', function () {
        $tenant = createTenant(['organization_type' => 'private', 'organization_type_needs_review' => true]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $url = 'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings';

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('organization.organization_type', 'private')
            ->assertJsonPath('organization.organization_type_needs_review', true);

        $this->putJson($url.'/organization', ['organization_type' => 'government'])
            ->assertOk()
            ->assertJsonPath('organization.organization_type', 'government')
            ->assertJsonPath('organization.organization_type_needs_review', false);

        expect(AuditLog::query()->where('action', 'settings.organization_updated')->latest('id')->first()?->payload)
            ->toBe(['organization_type' => ['from' => 'private', 'to' => 'government']]);
        $this->getJson($url)->assertJsonPath('payroll.fiscal_year_start_month', 11)
            ->assertJsonPath('payroll.pension_scheme', 'public');
    });

    it('refuses a type that is not in the catalogue', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization', ['organization_type' => 'cartel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_type']);
    });

    it('cannot be changed by someone without settings.manage', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->putJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization', ['organization_type' => 'government'])
            ->assertForbidden();
        expect($tenant->fresh()->organization_type)->toBe('private');
    });
});

it('infers the kind of organisation at registration and asks the admin to confirm it', function (string $industry, string $kind) {
    $this->postJson('/api/v1/auth/register', [
        'organization_name' => 'Kind Test', 'subdomain' => 'kind-test-'.$industry,
        'admin_name' => 'Admin User', 'admin_email' => "admin@{$industry}.example", 'admin_phone' => '+251911234567',
        'password' => 'SecurePass123!', 'password_confirmation' => 'SecurePass123!',
        'organization_type' => $industry,
    ])->assertCreated();

    $tenant = Tenant::query()->where('subdomain', 'kind-test-'.$industry)->sole();
    expect($tenant->organization_type)->toBe($kind)
        ->and($tenant->organization_type_needs_review)->toBeTrue()
        ->and($tenant->type)->toBe($industry);
})->with([
    'a ministry' => ['government', 'government'],
    'a charity' => ['ngo', 'ngo'],
    'a bank' => ['bank', 'private'],
]);

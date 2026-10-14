<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\PensionRule;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PensionCalculator;
use App\Services\Payroll\RunRegisterService;
use App\Services\Payroll\StatutorySchedules;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pension rules as dated data (2026-10-14). The rates were constants in
 * PensionCalculator; the law changes, so they are rows with a date and the law
 * they come from (docs/payroll/STATUTORY-RULES-RESEARCH.md), and every entry's
 * calculation_log says which rule it used.
 */
function pensionRun(Tenant $tenant, int $userId, string $from, string $to): PayrollRun
{
    return app(PayrollEngine::class)->process($tenant->id, Carbon::parse($from), Carbon::parse($to), $userId)->run;
}

function pensionStep(PayrollRun $run, string $code): array
{
    $entry = PayrollEntry::query()
        ->where('payroll_run_id', $run->id)
        ->whereHas('employee', fn ($q) => $q->where('employee_code', $code))
        ->sole();

    return collect($entry->calculation_log['steps'])->firstWhere('step', 'pension');
}

function pensionEmployee(Tenant $tenant, string $code, int $basic, array $extra = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id, 'employee_code' => $code, 'salary_cents' => $basic,
        'hire_date' => '2025-01-01', 'status' => 'confirmed', 'nationality' => 'Ethiopian', ...$extra,
    ]);
}

function platformPensionRule(string $scheme, float $employee, float $employer, string $from, string $reference, array $extra = []): void
{
    DB::table('pension_rules')->whereNull('tenant_id')->where('scheme', $scheme)->whereNull('effective_to')
        ->where('effective_from', '<', $from)
        ->update(['effective_to' => Carbon::parse($from)->subDay()->toDateString()]);

    DB::table('pension_rules')->insert([
        'public_id' => (string) Str::ulid(), 'tenant_id' => null, 'scheme' => $scheme,
        'employee_rate' => $employee, 'employer_rate' => $employer, 'base' => 'basic',
        'exempt_non_nationals' => true, 'legal_reference' => $reference,
        'effective_from' => $from, 'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

it('applies the seeded law and records it, leaving the golden payslips as they were', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'G4', 900000);

    $step = pensionStep(pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31'), 'G4');

    // G4 of the golden payslips: 9,000 ETB → 630 / 990.
    expect($step)->toMatchArray([
        'employee_pension_cents' => 63000,
        'employer_pension_cents' => 99000,
        'employee_rate' => 7.0,
        'employer_rate' => 11.0,
        'exempt' => false,
    ])->and($step['rule'])->toMatchArray([
        'scheme' => 'private',
        'legal_reference' => 'Proclamation No. 1268/2022, Art. 10',
        'effective_from' => '2022-03-18',
        'source' => 'platform',
    ]);
});

it('uses the old rate before a mid-year change and the new one after', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'E1', 1000000);
    platformPensionRule('private', 8, 12, '2026-11-01', 'Hypothetical Amendment No. 9999/2026');

    $october = pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31');
    $november = pensionRun($tenant, $user->id, '2026-11-01', '2026-11-30');

    expect(pensionStep($october, 'E1'))->toMatchArray(['employee_pension_cents' => 70000, 'employer_pension_cents' => 110000])
        ->and(pensionStep($november, 'E1'))->toMatchArray(['employee_pension_cents' => 80000, 'employer_pension_cents' => 120000])
        ->and(pensionStep($november, 'E1')['rule']['legal_reference'])->toBe('Hypothetical Amendment No. 9999/2026');

    // Reprocessing October after the change still gives October's rate.
    $october->update(['status' => 'voided']);
    $again = pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31');
    expect(pensionStep($again, 'E1')['employee_rate'])->toEqual(7.0);
});

it('holds contributions at a ceiling', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'HI', 2500000);
    platformPensionRule('private', 7, 11, '2026-10-01', 'Hypothetical ceiling', ['ceiling_cents' => 1000000]);

    expect(pensionStep(pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31'), 'HI'))->toMatchArray([
        'pensionable_cents' => 2500000,
        'contributory_cents' => 1000000,
        'employee_pension_cents' => 70000,
        'employer_pension_cents' => 110000,
    ]);
});

it('exempts a non-national, keeps them off the pension schedule, and leaves their tax alone', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'ET', 600000);
    pensionEmployee($tenant, 'KE', 600000, ['nationality' => 'Kenyan', 'nationality_status' => 'foreign']);

    $run = pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31');

    expect(pensionStep($run, 'KE'))->toMatchArray([
        'exempt' => true, 'exempt_reason' => 'non_national', 'employee_pension_cents' => 0, 'employer_pension_cents' => 0,
    ]);
    $kenyan = PayrollEntry::where('payroll_run_id', $run->id)->whereHas('employee', fn ($q) => $q->where('employee_code', 'KE'))->sole();
    // 6,000 ETB: tax 700, no pension, so net is 5,300.
    expect($kenyan->income_tax_cents)->toBe(70000)->and($kenyan->net_cents)->toBe(530000);

    $schedules = app(StatutorySchedules::class);
    expect(array_column($schedules->build($run, 'pension')['rows'], 'employee_code'))->toBe(['ET'])
        ->and(array_column($schedules->build($run, 'income-tax')['rows'], 'employee_code'))->toBe(['ET', 'KE']);
});

it('applies a minimum period of service when a rule sets one', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'NEW', 600000, ['hire_date' => '2026-10-20']);
    pensionEmployee($tenant, 'OLD', 600000);
    platformPensionRule('private', 7, 11, '2026-10-01', 'Hypothetical 45-day rule', ['min_service_days' => 45]);

    $run = pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31');

    expect(pensionStep($run, 'NEW'))->toMatchArray(['exempt' => true, 'exempt_reason' => 'min_service'])
        ->and(pensionStep($run, 'OLD')['exempt'])->toBeFalse();
});

it('labels the register and the schedule with the rate the run used, even after the rule changes', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'E1', 1000000);
    platformPensionRule('private', 8, 12.5, '2026-10-01', 'Hypothetical No. 1/2026');
    $run = pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31');

    platformPensionRule('private', 9, 13, '2026-10-15', 'Hypothetical No. 2/2026');

    $register = app(RunRegisterService::class)->csv($run);
    $schedule = app(StatutorySchedules::class)->csv($run, 'pension');

    expect($register)->toContain('Employee Pension 8% (ETB)')->toContain('Employer Pension 12.5% (ETB)')
        ->not->toContain('7%')->not->toContain('9%')
        ->and($schedule)->toContain('Employee 8% (ETB)')->toContain('Employer 12.5% (ETB)')->toContain('Total 20.5% (ETB)')
        ->toContain('Pension: Hypothetical No. 1/2026, effective 01/10/2026');
});

it('falls back to 7 / 11 only when no rule is in force, and says so', function () {
    DB::table('pension_rules')->delete();
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'E1', 1000000);

    $step = pensionStep(pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31'), 'E1');

    expect($step)->toMatchArray(['employee_pension_cents' => 70000, 'employer_pension_cents' => 110000])
        ->and($step['rule'])->toMatchArray(['source' => 'fallback', 'legal_reference' => PensionCalculator::FALLBACK_REFERENCE]);
});

it('uses the public schemes when the organisation is a public body', function (string $scheme, int $employer, string $reference) {
    $tenant = createTenant(['settings' => ['pension_scheme' => $scheme]]);
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    pensionEmployee($tenant, 'P1', 1000000);

    expect(pensionStep(pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31'), 'P1'))
        ->toMatchArray(['employer_pension_cents' => $employer, 'employee_pension_cents' => 70000])
        ->and(pensionStep(PayrollRun::latest('id')->first(), 'P1')['rule']['legal_reference'])->toBe($reference);
})->with([
    'civil service' => ['public', 110000, 'Proclamation No. 1267/2022, Art. 10'],
    'military and police' => ['public_military', 330000, 'Proclamation No. 1267/2022, Art. 11'],
]);

describe('Settings → Payroll → Pension rules', function () {
    it('lists the law and the organisation\'s own rules, and what is in force', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/pension-rules")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.owner', 'platform')
            ->assertJsonPath('in_force.legal_reference', 'Proclamation No. 1268/2022, Art. 10')
            ->assertJsonMissingPath('data.0.id');
    });

    it('adds a dated rule that needs its legal source, closes the previous one, and is audited', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $url = "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/pension-rules";
        $rule = ['scheme' => 'private', 'employee_rate' => 7, 'employer_rate' => 11, 'effective_from' => '2027-01-01'];

        test()->postJson($url, $rule)->assertUnprocessable()->assertJsonValidationErrors('legal_reference');
        test()->postJson($url, [...$rule, 'legal_reference' => 'Board resolution 12/2026'])->assertCreated()
            ->assertJsonPath('owner', 'organisation');
        test()->postJson($url, [...$rule, 'employee_rate' => 8, 'effective_from' => '2027-06-01', 'legal_reference' => 'Directive 3/2027'])
            ->assertCreated();
        test()->postJson($url, [...$rule, 'effective_from' => '2027-03-01', 'legal_reference' => 'Out of order'])
            ->assertUnprocessable();

        expect(PensionRule::query()->orderBy('effective_from')->pluck('effective_to')->map(fn ($d) => $d?->format('Y-m-d'))->all())
            ->toBe(['2027-05-31', null])
            ->and(AuditLog::where('action', 'pension_rule.created')->count())->toBe(2);
    });

    it('lets only those who manage payroll configuration add one', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/pension-rules", [
            'scheme' => 'private', 'employee_rate' => 7, 'employer_rate' => 11,
            'effective_from' => '2027-01-01', 'legal_reference' => 'x x x',
        ])->assertForbidden();
    });

    it('keeps one organisation\'s rules from another', function () {
        $other = createTenant();
        PensionRule::create([
            'scheme' => 'private', 'employee_rate' => 1, 'employer_rate' => 1,
            'legal_reference' => 'Other organisation', 'effective_from' => '2026-01-01',
        ]);
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
        pensionEmployee($tenant, 'E1', 1000000);

        expect(pensionStep(pensionRun($tenant, $user->id, '2026-10-01', '2026-10-31'), 'E1')['employee_rate'])->toEqual(7.0);
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/pension-rules")
            ->assertJsonMissing(['legal_reference' => 'Other organisation']);
        expect($other->id)->not->toBe($tenant->id);
    });
});

describe('statutory layouts in the platform console', function () {
    it('adds a new form version from a date, which the schedules then use', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

        test()->postJson('/api/v1/admin/statutory-layouts', [
            'schedule' => 'pension', 'scheme' => 'private',
            'agency' => 'POESSA', 'title' => 'Monthly Contribution Form 2027',
            'effective_from' => '2027-01-01',
            'columns' => [
                ['key' => 'tin', 'label' => 'TIN'],
                ['key' => 'employee_name', 'label' => 'Name'],
                ['key' => 'total_pension', 'label' => 'Contribution {total_rate}%'],
            ],
        ])->assertCreated();

        $schedules = app(StatutorySchedules::class);
        expect($schedules->layout('pension', 'private', Carbon::parse('2027-02-01'))['title'])->toBe('Monthly Contribution Form 2027')
            ->and($schedules->layout('pension', 'private', Carbon::parse('2026-12-01'))['title'])->toBe('Pension Contribution Schedule');

        test()->getJson('/api/v1/admin/statutory-layouts')->assertOk()->assertJsonCount(5, 'data');
    });

    it('refuses a column the schedules cannot fill, and anyone but the platform', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
        test()->postJson('/api/v1/admin/statutory-layouts', [
            'schedule' => 'income-tax', 'agency' => 'MoR', 'title' => 'T', 'effective_from' => '2027-01-01',
            'columns' => [['key' => 'salary_of_the_ceo', 'label' => 'x']],
        ])->assertUnprocessable();

        actingAsUser(['role' => UserRole::TENANT_ADMIN], createTenant());
        expect(test()->getJson('/api/v1/admin/statutory-layouts')->status())->toBeIn([403, 404]);
    });
});

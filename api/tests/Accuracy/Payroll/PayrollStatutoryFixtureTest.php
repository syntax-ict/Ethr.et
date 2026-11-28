<?php

declare(strict_types=1);

use App\Models\OrganizationType;
use App\Services\Payroll\PensionCalculator;
use App\Services\Payroll\StatutoryRules;
use App\Services\Payroll\TaxCalculator;
use Carbon\Carbon;
use Database\Seeders\TaxBracketSeeder;
use Tests\Accuracy\Payroll\PayrollCases;

/*
 * The hand values assume the statutory values of fixtures/statutory.php. This
 * test checks that the application, after its migrations and the tax bracket
 * seeder, resolves exactly those values: a wrong fixture fails here, not as a
 * puzzling mismatch in forty cases.
 */

it('runs on the values the register states', function (): void {
    $this->seed(TaxBracketSeeder::class);
    $fixture = PayrollCases::statutory();
    $on = Carbon::parse('2026-10-01');
    $rules = app(StatutoryRules::class);

    $platform = $rules->platform($on);
    expect($platform->int('payroll.working_days_per_month'))->toBe($fixture['working_days_per_month']);
    expect($platform->int('payroll.hours_per_day'))->toBe($fixture['hours_per_day']);
    expect($platform->string('pagume.proration'))->toBe($fixture['pagume']);

    $minimum = [];
    foreach ($platform->list('overtime.minimum_rates') as $kind => $rate) {
        $minimum[$kind] = (int) round($rate * 100);
    }
    expect($minimum)->toBe($fixture['overtime_minimum_rates_x100']);

    $ratio = static fn (array $r): array => [(int) $r['numerator'], (int) $r['denominator']];
    expect($ratio($platform->list('payroll.deduction_cap_ratio')))->toBe($fixture['deduction_cap_ratio']);
    expect($ratio($platform->list('leave.sick.half_pay_ratio')))->toBe($fixture['sick_half_pay_ratio']);

    $tiers = $platform->list('leave.sick.tiers');
    expect(['full' => (int) $tiers['full_months'], 'half' => (int) $tiers['half_months'], 'unpaid' => (int) $tiers['unpaid_months']])
        ->toBe($fixture['sick_tiers_months']);
    expect($platform->int('leave.sick.window_months'))->toBe($fixture['sick_window_months']);

    // The scheme every organisation kind resolves to, for every kind the application lists.
    $kinds = OrganizationType::query()->pluck('code')->all();
    expect($kinds)->not->toBeEmpty();
    foreach ($kinds as $kind) {
        $tenant = createTenant(['organization_type' => $kind]);
        $rules->forget();
        $want = $fixture['pension_scheme_by_kind'][$kind] ?? $fixture['pension_scheme_by_kind']['*'];
        expect($rules->for($tenant, $on)->string('pension.default_scheme'))->toBe($want, "organisation kind {$kind}");
    }
    foreach (array_keys($fixture['pension_scheme_by_kind']) as $kind) {
        if ($kind !== '*') {
            expect($kinds)->toContain($kind);
        }
    }

    $pension = app(PensionCalculator::class);
    foreach ($fixture['pension_rates_pct'] as $scheme => [$employee, $employer]) {
        $rule = $pension->rule(null, $scheme, $on);
        expect([(float) $rule->employeeRate, (float) $rule->employerRate])->toBe([(float) $employee, (float) $employer], "pension scheme {$scheme}");
    }

    $tax = app(TaxCalculator::class);
    foreach ($fixture['income_tax_ladders'] as $ladder) {
        foreach ([$ladder['from'], $ladder['to'] ?? '2030-01-01'] as $day) {
            $mine = array_map(
                static fn (array $b): array => [$b['min'], $b['max'], (float) $b['rate'], $b['deduction']],
                $tax->ladder(null, Carbon::parse($day)),
            );
            $hand = array_map(static fn (array $b): array => [$b[0], $b[1], (float) $b[2], $b[3]], $ladder['bands']);
            expect($mine)->toBe($hand, "income tax ladder on {$day}");
        }
    }
});

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
    $rules = app(StatutoryRules::class);
    $pension = app(PensionCalculator::class);
    $tax = app(TaxCalculator::class);

    // The earliest and the latest period start any case uses, and the day the
    // suite was first written on: the values must hold across the cases' span.
    $starts = array_map(static fn (array $case): string => $case['period']['start'], PayrollCases::raw());
    sort($starts);
    $dates = array_values(array_unique(['2026-10-01', $starts[0], $starts[count($starts) - 1]]));

    $kinds = OrganizationType::query()->pluck('code')->all();
    expect($kinds)->not->toBeEmpty();
    foreach (array_keys($fixture['pension_scheme_by_kind']) as $kind) {
        if ($kind !== '*') {
            expect($kinds)->toContain($kind);
        }
    }
    $tenants = [];
    foreach ($kinds as $kind) {
        $tenants[$kind] = createTenant(['organization_type' => $kind]);
    }

    foreach ($dates as $day) {
        $on = Carbon::parse($day);
        $rules->forget();

        $platform = $rules->platform($on);
        expect($platform->int('payroll.working_days_per_month'))->toBe($fixture['working_days_per_month'], "working days on {$day}");
        expect($platform->int('payroll.hours_per_day'))->toBe($fixture['hours_per_day'], "hours on {$day}");
        expect($platform->string('pagume.proration'))->toBe($fixture['pagume'], "pagume on {$day}");

        $minimum = [];
        foreach ($platform->list('overtime.minimum_rates') as $kind => $rate) {
            $minimum[$kind] = (int) round($rate * 100);
        }
        expect($minimum)->toBe($fixture['overtime_minimum_rates_x100'], "overtime floors on {$day}");

        $ratio = static fn (array $r): array => [(int) $r['numerator'], (int) $r['denominator']];
        expect($ratio($platform->list('payroll.deduction_cap_ratio')))->toBe($fixture['deduction_cap_ratio'], "cap on {$day}");
        expect($ratio($platform->list('leave.sick.half_pay_ratio')))->toBe($fixture['sick_half_pay_ratio'], "half pay on {$day}");

        $tiers = $platform->list('leave.sick.tiers');
        expect(['full' => (int) $tiers['full_months'], 'half' => (int) $tiers['half_months'], 'unpaid' => (int) $tiers['unpaid_months']])
            ->toBe($fixture['sick_tiers_months'], "sick tiers on {$day}");
        expect($platform->int('leave.sick.window_months'))->toBe($fixture['sick_window_months'], "sick window on {$day}");

        // The scheme every organisation kind resolves to, for every kind the application lists.
        foreach ($tenants as $kind => $tenant) {
            $want = $fixture['pension_scheme_by_kind'][$kind] ?? $fixture['pension_scheme_by_kind']['*'];
            expect($rules->for($tenant, $on)->string('pension.default_scheme'))->toBe($want, "organisation kind {$kind} on {$day}");
        }

        foreach ($fixture['pension_rates_pct'] as $scheme => [$employee, $employer]) {
            $rule = $pension->rule(null, $scheme, $on);
            expect([(float) $rule->employeeRate, (float) $rule->employerRate])->toBe([(float) $employee, (float) $employer], "pension scheme {$scheme} on {$day}");
        }

        $ladder = null;
        foreach ($fixture['income_tax_ladders'] as $candidate) {
            if (strcmp($candidate['from'], $day) <= 0 && ($candidate['to'] === null || strcmp($day, $candidate['to']) <= 0)) {
                $ladder = $candidate;
            }
        }
        expect($ladder)->not->toBeNull("a tax ladder in the fixture for {$day}");
        $mine = array_map(
            static fn (array $b): array => [$b['min'], $b['max'], (float) $b['rate'], $b['deduction']],
            $tax->ladder(null, $on),
        );
        $hand = array_map(static fn (array $b): array => [$b[0], $b[1], (float) $b[2], $b[3]], $ladder['bands']);
        expect($mine)->toBe($hand, "income tax ladder on {$day}");
    }

    // Both ends of each ladder, whatever the cases use.
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

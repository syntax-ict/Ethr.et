<?php

declare(strict_types=1);

/*
 * The statutory values every payroll case runs on, from docs/payroll/PAYROLL-RULES.md
 * ("Statutory parameters used", PYR-TAX-01, PYR-TAX-02, PYR-PEN-02). DATA ONLY.
 * Integers only: multipliers x100, rates in whole percent, ratios as [numerator, denominator].
 */

return [
    'working_days_per_month' => 22,
    'hours_per_day' => 8,
    'overtime_minimum_rates_x100' => ['normal' => 150, 'night' => 175, 'rest_day' => 200, 'holiday' => 250, 'holiday_night' => 250],
    'pagume' => 'full_month',
    'deduction_cap_ratio' => [1, 3],
    'sick_half_pay_ratio' => [1, 2],
    'sick_tiers_months' => ['full' => 1, 'half' => 2, 'unpaid' => 3],
    'sick_window_months' => 12,
    // pension.default_scheme by organisation kind; '*' is every other kind
    'pension_scheme_by_kind' => ['*' => 'private', 'government' => 'public', 'regional_government' => 'public', 'public_enterprise' => 'public', 'defence_police' => 'public_military'],
    // [employee %, employer %]
    'pension_rates_pct' => ['private' => [7, 11], 'public' => [7, 11], 'public_military' => [7, 33]],
    'pension_exempt_nationality' => ['foreign', 'ethiopian_origin'],
    // the kinds PYR-PEN-11 names
    'pension_p9_kinds' => ['international_organization', 'diplomatic_mission', 'sole_proprietor'],
    // each band: [floor cents, top cents or null, rate %, deduction cents]
    'income_tax_ladders' => [
        ['from' => '2016-07-08', 'to' => '2025-07-06', 'bands' => [
            [0, 60000, 0, 0], [60001, 165000, 10, 6000], [165001, 320000, 15, 14250], [320001, 525000, 20, 30250],
            [525001, 780000, 25, 56500], [780001, 1090000, 30, 95500], [1090001, null, 35, 150000],
        ]],
        ['from' => '2025-07-07', 'to' => null, 'bands' => [
            [0, 200000, 0, 0], [200001, 400000, 15, 30000], [400001, 700000, 20, 50000], [700001, 1000000, 25, 85000],
            [1000001, 1400000, 30, 135000], [1400001, null, 35, 205000],
        ]],
    ],
];

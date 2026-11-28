<?php

declare(strict_types=1);

/*
 * Yearly entitlement (LVR-ENT-01, LVR-ENT-03, LVR-ENT-04, LVR-ENT-08). DATA ONLY.
 *
 * Every case here cites LVR-ENT-01, whose value is LVR-ENT-02 (Undecided, legal, item 2), so the
 * register ("How to read a row") has them reported as waiting; they are typed now and assert on
 * the day item 2 is signed. Built on the type's OWN values (default 16, one more day for every 2
 * completed years), never on the statutory ones, so a case isolates the
 * arithmetic from LVR-ENT-02. LVR-ENT-03 is ruled only where its two readings
 * agree: 1, 3 and 5 completed years. At 2 and 4 the readings differ and the
 * case waits for item 1; `readings` holds both, `expect` the code's reading A.
 */

$step = ['default_days' => 16, 'seniority_every_years' => 2, 'seniority_extra_days' => 1];

$e = fn (string $id, array|string $rule, string $source, ?string $hire, string $asOf, array $working, int $tenths, array $extra = [], array $type = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'as_of' => $asOf,
    'employee' => ['hire_date' => $hire],
    'type' => [...$step, ...$type],
    'expect' => ['entitlement_tenths' => $tenths],
    ...$extra,
];

$waitItem1 = fn (int $a, int $b): array => ['signed' => null, 'waits_on' => '1', 'readings' => ['A' => ['entitlement_tenths' => $a], 'B' => ['entitlement_tenths' => $b]]];

return [
    $e('GL-ENT-001', ['LVR-ENT-01', 'LVR-ENT-03'], 'register', '2025-06-01', '2026-12-31', [
        'hired 2025-06-01, taken 2026-12-31: 1 completed year',
        'readings A and B agree at 1 year (table, item 1): 16',
        '16 days = 160 tenths',
    ], 160, ['signed' => null, 'waits_on' => '2']),
    $e('GL-ENT-002', ['LVR-ENT-01', 'LVR-ENT-03'], 'register', '2023-06-01', '2026-12-31', [
        'hired 2023-06-01, taken 2026-12-31: 3 completed years',
        'readings A and B agree at 3 years (table, item 1): 17',
        '17 days = 170 tenths',
    ], 170, ['signed' => null, 'waits_on' => '2']),
    $e('GL-ENT-003', ['LVR-ENT-01', 'LVR-ENT-03'], 'register', '2021-06-01', '2026-12-31', [
        'hired 2021-06-01, taken 2026-12-31: 5 completed years',
        'readings A and B agree at 5 years (table, item 1): 18',
        '18 days = 180 tenths',
    ], 180, ['signed' => null, 'waits_on' => '2']),
    $e('GL-ENT-004', ['LVR-ENT-01', 'LVR-ENT-03'], 'register, waiting item 1', '2024-06-01', '2026-12-31', [
        'hired 2024-06-01, taken 2026-12-31: 2 completed years',
        'reading A (the code): 17. Reading B: 16',
    ], 170, $waitItem1(170, 160)),
    $e('GL-ENT-005', ['LVR-ENT-01', 'LVR-ENT-03'], 'register (item 1 example), 06 LB-002', '2022-06-01', '2026-12-31', [
        'hired 2022-06-01, taken 2026-12-31: 4 completed years',
        'reading A (the code, and 06 LB-002): 16 + 2 x 1 = 18. Reading B: 17',
    ], 180, $waitItem1(180, 170)),
    // LVR-ENT-04: the anniversary day itself completes the year.
    $e('GL-ENT-006', 'LVR-ENT-04', 'register', '2024-06-01', '2026-05-31', [
        'hired 2024-06-01, taken 2026-05-31: the day before the second anniversary, 1 completed year',
        '1 year: 16 under both readings = 160 tenths',
    ], 160),
    $e('GL-ENT-007', ['LVR-ENT-04', 'LVR-ENT-03'], 'register, waiting item 1', '2024-06-01', '2026-06-01', [
        'hired 2024-06-01, taken 2026-06-01: the anniversary itself completes year 2',
        '2 completed years: reading A 17, reading B 16',
    ], 170, $waitItem1(170, 160)),
    $e('GL-ENT-008', 'LVR-ENT-04', 'register', '2024-02-29', '2026-02-28', [
        'hired 2024-02-29 (a leap day), taken 2026-02-28: 1 completed year (2 on 2026-03-01)',
        '1 year: 16 = 160 tenths',
    ], 160),
    $e('GL-ENT-009', ['LVR-ENT-04', 'LVR-ENT-03'], 'register, waiting item 1', '2024-02-29', '2026-03-01', [
        'hired 2024-02-29, taken 2026-03-01: 2 completed years',
        'reading A 17, reading B 16',
    ], 170, $waitItem1(170, 160)),
    $e('GL-ENT-010', ['LVR-ENT-04', 'LVR-ENT-03'], 'register, waiting item 1', '2024-02-29', '2028-02-29', [
        'hired 2024-02-29, taken 2028-02-29: 4 completed years',
        'reading A 18, reading B 17',
    ], 180, $waitItem1(180, 170)),
    $e('GL-ENT-011', 'LVR-ENT-08', 'register', null, '2026-12-31', [
        'no hire date: no seniority step, whatever the date',
        '16 days = 160 tenths',
    ], 160),
    $e('GL-ENT-012', 'LVR-ENT-01', 'register', '2016-01-01', '2026-12-31', [
        'both step fields must be above zero; with the step cleared (every 0, extra 0) the entitlement is the type\'s default',
        '10 completed years, no step: 16 = 160 tenths',
    ], 160, ['signed' => null, 'waits_on' => '2'], ['seniority_every_years' => 0, 'seniority_extra_days' => 0]),
    $e('GL-ENT-013', 'LVR-ENT-01', 'register', '2026-12-31', '2026-12-31', [
        'the step needs a hire date BEFORE the date taken: hired the same day, 0 completed years',
        '16 = 160 tenths',
    ], 160, ['signed' => null, 'waits_on' => '2']),
];

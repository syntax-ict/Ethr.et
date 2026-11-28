<?php

declare(strict_types=1);

/*
 * Accrual (LVR-ACC-01 to LVR-ACC-11, LVR-YEAR-02, LVR-YEAR-04). DATA ONLY.
 *
 * The accrual job runs at 00:30 UTC on each `at`. `expect.after` has one
 * entry per act, in order: the balance row of the named leave-year number
 * after that act. Entitled values are tenths of a day. The default type is
 * 16 days, monthly accrual, no seniority step, and the default employee was
 * hired 2020-01-01, so every case that does not say otherwise isolates the
 * target arithmetic round(entitlement x months / 12, 1) from the step.
 */

$acc = fn (string $at): array => ['at' => $at.' 00:30', 'do' => 'accrue'];
$ent = fn (int $year, int $tenths): array => ['balance' => [$year => ['entitled_tenths' => $tenths]]];

$c = fn (string $id, array|string $rule, string $source, array $working, array $act, array $after, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'act' => $act,
    'expect' => ['after' => $after],
    ...$extra,
];

return [
    $c('GL-ACC-001', ['LVR-ACC-01', 'LVR-ACC-11'], 'register', [
        '16 days, no step, employed all of Gregorian 2026',
        '1 January, month 1: round(16 x 1 / 12, 1) = round(1.333, 1) = 1.3',
        '1 February, month 2: round(16 x 2 / 12, 1) = round(2.667, 1) = 2.7',
        '1 December, month 12: 16 x 12 / 12 = 16.0',
    ], [$acc('2026-01-01'), $acc('2026-02-01'), $acc('2026-12-01')], [$ent(2026, 13), $ent(2026, 27), $ent(2026, 160)]),

    $c('GL-ACC-002', ['LVR-ACC-01', 'LVR-ACC-02', 'LVR-ACC-11'], 'register', [
        'round(16 x m / 12, 1) for m = 1 to 12, each on the first day of the month',
        'm=1 1.333 -> 1.3; m=2 2.667 -> 2.7; m=3 4.000 -> 4.0; m=4 5.333 -> 5.3; m=5 6.667 -> 6.7; m=6 8.000 -> 8.0',
        'm=7 9.333 -> 9.3; m=8 10.667 -> 10.7; m=9 12.000 -> 12.0; m=10 13.333 -> 13.3; m=11 14.667 -> 14.7; m=12 16.0',
        'the target is rounded, not each month\'s share, so month 12 is exactly 16.0',
    ], array_map($acc, [
        '2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01',
        '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01',
    ]), array_map(fn (int $t): array => $ent(2026, $t), [13, 27, 40, 53, 67, 80, 93, 107, 120, 133, 147, 160])),

    $c('GL-ACC-003', ['LVR-ACC-01', 'LVR-ACC-02', 'LVR-ACC-11'], 'register', [
        'a 20-day type: 1 January round(20 x 1 / 12, 1) = round(1.667, 1) = 1.7',
        '1 December: 20 x 12 / 12 = 20.0, not 12 x 1.7 = 20.4',
    ], [$acc('2026-01-01'), $acc('2026-12-01')], [$ent(2026, 17), $ent(2026, 200)], ['type' => ['default_days' => 20]]),

    $c('GL-ACC-004', ['LVR-ACC-01', 'LVR-ACC-11'], 'register', [
        'a 17-day type, 1 March (month 3): 17 x 3 / 12 = 4.25',
        'PHP round() is half away from zero on the exact quotient: 4.3 (not 4.2)',
    ], [$acc('2026-03-01')], [$ent(2026, 43)], ['type' => ['default_days' => 17]]),

    $c('GL-ACC-005', ['LVR-ACC-04', 'LVR-ACC-05'], 'register, waiting item 8', [
        'hired 2026-03-31, 16 days, read on 1 June 2026 (June credited in advance)',
        'reading A (the code): March counts whole, March to June = 4 months: 16 x 4 / 12 = 5.33 -> 5.3',
        'reading B: complete months only, 3: 4.0. Reading C: by days, 92 of 365: 4.03 -> 4.0',
    ], [$acc('2026-06-01')], [$ent(2026, 53)], [
        'employee' => ['hire_date' => '2026-03-31'],
        'signed' => null, 'waits_on' => '8',
        'readings' => ['A' => ['after' => [$ent(2026, 53)]], 'B' => ['after' => [$ent(2026, 40)]], 'C' => ['after' => [$ent(2026, 40)]]],
    ]),

    $c('GL-ACC-006', ['LVR-ACC-04', 'LVR-ACC-01'], 'register', [
        'hired on the first of the month, 2026-03-01: the whole of March is worked, so no part-month question',
        'read on 1 June 2026: March, April, May, June = 4 months: 16 x 4 / 12 = 5.33 -> 5.3',
    ], [$acc('2026-06-01')], [$ent(2026, 53)], ['employee' => ['hire_date' => '2026-03-01']]),

    $c('GL-ACC-007', ['LVR-ACC-04', 'LVR-ACC-05'], 'register, waiting item 8', [
        'hired 2026-01-01, left 2026-02-20, Gregorian year, read on 1 September 2026',
        'reading A (the code): January and February counted whole = 2 months: 16 x 2 / 12 = 2.67 -> 2.7, and still 2.7 in September',
        'reading B (complete months) would count January only; the register does not give its number',
    ], [$acc('2026-09-01')], [$ent(2026, 27)], [
        'employee' => ['hire_date' => '2026-01-01', 'termination_date' => '2026-02-20'],
        'signed' => null, 'waits_on' => '8',
    ]),

    $c('GL-ACC-008', ['LVR-ACC-04', 'LVR-ACC-05', 'LVR-YEAR-04'], 'register, waiting item 8', [
        'the same leaver on the Ethiopian year: 2026-01-01 is in Tahsas (month 4), 2026-02-20 in Yekatit (month 6) of year 2018',
        'reading A (the code): months 4, 5, 6 = 3 months: 16 x 3 / 12 = 4.0',
    ], [$acc('2026-09-01')], [$ent(2018, 40)], [
        'org' => ['leave_year' => 'ethiopian'],
        'employee' => ['hire_date' => '2026-01-01', 'termination_date' => '2026-02-20'],
        'signed' => null, 'waits_on' => '8',
    ]),

    $c('GL-ACC-009', ['LVR-YEAR-02', 'LVR-YEAR-04', 'LVR-ACC-01'], 'register', [
        'Ethiopian leave year, employed since 2020; year 2018 begins Meskerem 1 = 2025-09-11, Tikimt 1 = 2025-10-11, Pagume 1 = 2026-09-06',
        '2025-09-11 (month 1): 1.3, balance year 2018',
        '2025-10-11 (month 2): 2.7',
        '2026-09-06 (Pagume, part of month 12, no 13th month): 16.0; 2026-09-10 (Pagume 5): still 16.0',
        '2026-09-11 is Meskerem 1 of year 2019: a new balance row, month 1: 1.3; the 2018 row stays 16.0',
    ], [$acc('2025-09-11'), $acc('2025-10-11'), $acc('2026-09-06'), $acc('2026-09-10'), $acc('2026-09-11')], [
        $ent(2018, 13), $ent(2018, 27), $ent(2018, 160), $ent(2018, 160),
        ['balance' => [2019 => ['entitled_tenths' => 13], 2018 => ['entitled_tenths' => 160]]],
    ], ['org' => ['leave_year' => 'ethiopian']]),

    $c('GL-ACC-010', ['LVR-YEAR-04', 'LVR-ACC-01', 'LVR-ACC-04', 'LVR-ACC-05'], 'register, waiting item 8', [
        'hired 2026-09-06, the first day of Pagume, which is part of month 12 (Nehase plus Pagume): a part month',
        'reading A (the code): month 12 counts whole, 1 month: 16 x 1 / 12 = 1.3, balance year 2018',
        'on 2026-09-11 (Meskerem 1, year 2019) month 1: 1.3, balance year 2019',
    ], [$acc('2026-09-06'), $acc('2026-09-11')], [
        $ent(2018, 13),
        ['balance' => [2019 => ['entitled_tenths' => 13], 2018 => ['entitled_tenths' => 13]]],
    ], ['org' => ['leave_year' => 'ethiopian'], 'employee' => ['hire_date' => '2026-09-06'], 'signed' => null, 'waits_on' => '8']),

    $c('GL-ACC-024', ['LVR-YEAR-02', 'LVR-YEAR-04', 'LVR-ACC-01'], 'register', [
        'hired 2026-09-06 (Ethiopian year 2018), read on 2026-09-11, Meskerem 1 of year 2019',
        'hired in an earlier year than the one read: counted from month 1: 16 x 1 / 12 = 1.3, balance year 2019',
    ], [$acc('2026-09-11')], [$ent(2019, 13)], [
        'org' => ['leave_year' => 'ethiopian'], 'employee' => ['hire_date' => '2026-09-06'],
    ]),

    $c('GL-ACC-011', ['LVR-YEAR-04', 'LVR-ACC-04', 'LVR-ACC-05'], 'register, waiting item 8', [
        'hired 2026-09-07 (Pagume 2 of year 2018): counted as hired in month 12 of 2018 (register example)',
        'reading A (the code): 1 month = 1.3',
    ], [$acc('2026-09-08')], [$ent(2018, 13)], [
        'org' => ['leave_year' => 'ethiopian'], 'employee' => ['hire_date' => '2026-09-07'],
        'signed' => null, 'waits_on' => '8',
    ]),

    $c('GL-ACC-012', ['LVR-ACC-08'], 'register', [
        'annual accrual, 30 days: the whole yearly entitlement when the year\'s balance is first created',
        '2026-01-01: 30.0; never recomputed, so 2026-07-01 is still 30.0',
    ], [$acc('2026-01-01'), $acc('2026-07-01')], [$ent(2026, 300), $ent(2026, 300)], [
        'type' => ['default_days' => 30, 'accrual' => 'annual'],
    ]),

    $c('GL-ACC-013', ['LVR-ACC-08', 'LVR-ACC-05'], 'register, waiting item 8', [
        'a 30-day annual-accrual type, hired 2026-06-01: a joiner is not prorated, the full 30 for 2026',
        'reading A (the code): 30.0. Prorating readings (item 8) would give less',
    ], [$acc('2026-06-01')], [$ent(2026, 300)], [
        'type' => ['default_days' => 30, 'accrual' => 'annual'],
        'employee' => ['hire_date' => '2026-06-01'],
        'signed' => null, 'waits_on' => '8',
    ]),

    $c('GL-ACC-014', ['LVR-ACC-07', 'LVR-ENT-03'], 'register, waiting items 1 and 7', [
        'hired 2024-03-15, Gregorian 2026, annual 16 with the step (every 2, extra 1), monthly accrual',
        'reading A (the code), the entitlement is taken at the last day of the month:',
        '1 February: 16 x 2 / 12 = 2.67 -> 2.7 (2 months, 1 completed year at 28 February)',
        '1 March: 17 x 3 / 12 = 4.25 -> 4.3 (2 completed years on 31 March, so 17)',
        '1 December: 17 x 12 / 12 = 17.0',
        'reading B (item 7): (2 x 16 + 10 x 17) / 12 = 16.83 -> 16.8 at the year end',
    ], [$acc('2026-02-01'), $acc('2026-03-01'), $acc('2026-12-01')], [$ent(2026, 27), $ent(2026, 43), $ent(2026, 170)], [
        'type' => ['default_days' => 16, 'seniority_every_years' => 2, 'seniority_extra_days' => 1],
        'employee' => ['hire_date' => '2024-03-15'],
        'signed' => null, 'waits_on' => '1, 7',
        'readings' => ['B' => ['after' => [$ent(2026, 27), null, $ent(2026, 168)]]],
    ]),

    $c('GL-ACC-015', ['LVR-ACC-08', 'LVR-ACC-07', 'LVR-ENT-03'], 'register, waiting items 1 and 7', [
        'annual accrual 16 with the step, hired 2024-03-15',
        '2026-01-01: 1 completed year, no step: 16.0',
        '2027-01-01: 2 completed years, the step: 17.0 (reading A of item 1 and reading C of item 7)',
    ], [$acc('2026-01-01'), $acc('2027-01-01')], [$ent(2026, 160), $ent(2027, 170)], [
        'type' => ['default_days' => 16, 'accrual' => 'annual', 'seniority_every_years' => 2, 'seniority_extra_days' => 1],
        'employee' => ['hire_date' => '2024-03-15'],
        'signed' => null, 'waits_on' => '1, 7',
    ]),

    $c('GL-ACC-016', ['LVR-ACC-03', 'LVR-ACC-06'], 'register', [
        'accrued 8.0 on 1 June 2026 (month 6 of 16 days)',
        'a termination date of 2026-02-20 is then recorded: the target is lower, the balance does not fall',
        'the 1 September run leaves 8.0 (the target 2.7 is below it)',
    ], [$acc('2026-06-01'), ['do' => 'terminate', 'date' => '2026-02-20'], $acc('2026-09-01')], [
        $ent(2026, 80), null, $ent(2026, 80),
    ]),

    $c('GL-ACC-017', 'LVR-ACC-02', 'register', [
        'running accrual twice for the same moment changes nothing: 1 March, 16 x 3 / 12 = 4.0 both times',
    ], [$acc('2026-03-01'), $acc('2026-03-01')], [$ent(2026, 40), $ent(2026, 40)]),

    $c('GL-ACC-018', 'LVR-ACC-02', 'register', [
        'a missed day is caught up: the first run is on 15 June (month 6 of the year): 16 x 6 / 12 = 8.0',
    ], [$acc('2026-06-15')], [$ent(2026, 80)]),

    $c('GL-ACC-019', ['LVR-ACC-03', 'LVR-ACC-10'], 'register (the ACC-10 state, undecided)', [
        'a balance already holds 10.0 entitled; the 1 January target is 1.3, the 1 July target 9.3',
        'accrual never lowers a balance: 10.0 both times',
    ], [$acc('2026-01-01'), $acc('2026-07-01')], [$ent(2026, 100), $ent(2026, 100)], [
        'balances' => [['year' => 2026, 'entitled_tenths' => 100, 'used_tenths' => 0, 'carried_tenths' => 0, 'pending_tenths' => 0]],
        'undecided' => true,
    ]),

    $c('GL-ACC-021', ['LVR-YEAR-02', 'LVR-ACC-01'], '06 LB-010', [
        'Ethiopian year, wizard\'s annual type (16, step every 2 extra 1), hired 2025-03-01 (before 2026-09-11, under 2 years)',
        '2026-10-11 is Tikimt 1 of year 2019 (month 2): 16 x 2 / 12 = 2.7, balance year 2019 (not 2026)',
        '2026-11-10 is Hidar 1 (month 3): 16 x 3 / 12 = 4.0 (the Gregorian November would give 14.7)',
        '1 completed year at either entitlement date, so no step under either reading',
    ], [$acc('2026-10-11'), $acc('2026-11-10')], [$ent(2019, 27), $ent(2019, 40)], [
        'org' => ['leave_year' => 'ethiopian'],
        'type' => ['default_days' => 16, 'seniority_every_years' => 2, 'seniority_extra_days' => 1],
        'employee' => ['hire_date' => '2025-03-01'],
    ]),

    // Undecided rows: typed from the register's reading A, never asserted.
    $c('GL-ACC-022', 'LVR-ACC-10', 'register', [
        '10.0 imported for a 16-day monthly type holds accrual still until the target passes it',
        '1 July: target 9.3, nothing added: 10.0. 1 August: target 10.7 adds 0.7: 10.7',
    ], [$acc('2026-07-01'), $acc('2026-08-01')], [$ent(2026, 100), $ent(2026, 107)], [
        'undecided' => true,
        'balances' => [['year' => 2026, 'entitled_tenths' => 100, 'used_tenths' => 0, 'carried_tenths' => 0, 'pending_tenths' => 0]],
    ]),

    $c('GL-ACC-023', 'LVR-ACC-09', 'register', [
        'a one-time type (marriage, 3 days) refills every leave year exactly as an annual type',
        '2026-01-01: 3.0; 2027-01-01: 3.0 again (AZ E-07 expects one entitlement per occurrence)',
    ], [$acc('2026-01-01'), $acc('2027-01-01')], [$ent(2026, 30), $ent(2027, 30)], [
        'undecided' => true,
        'type' => ['code' => 'marriage', 'default_days' => 3, 'accrual' => 'one_time'],
    ]),
];

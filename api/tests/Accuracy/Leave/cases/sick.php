<?php

declare(strict_types=1);

/*
 * Art. 85 sick leave (LVR-SICK-01 to LVR-SICK-13). DATA ONLY.
 *
 * Every case here uses the statutory values (1 month full, 2 half, 3 unpaid,
 * a 12-month window, a month of 22 working days), which are LVR-SICK-07
 * (Undecided, legal, item 4). Each case therefore waits for item 4, and the
 * ones that also depend on the fixed-or-rolling window wait for item 3.
 * Typed now, from the register's reading A; `readings` holds reading B where
 * the register works it.
 *
 * Dates are worked by hand on Monday to Friday with no holiday. The n-th
 * charged day of a run that starts on a Monday is that Monday + 7 x intdiv(n-1, 5)
 * + (n-1) mod 5 days.
 */

$sick = ['code' => 'sick', 'default_days' => 30, 'follows_art85' => true];
$ap = fn (string $key, string $from, string $to, int $days): array => ['key' => $key, 'from' => $from, 'to' => $to, 'days' => $days, 'status' => 'approved'];

$c = fn (string $id, array|string $rule, string $source, string $waiting, array $working, array $requests, array $expect, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'type' => $sick,
    'requests' => $requests,
    'expect' => $expect,
    'signed' => null,
    'waiting' => $waiting,
    ...$extra,
];

return [
    $c('GL-SICK-001', ['LVR-SICK-06', 'LVR-SICK-02'], 'register', '4', [
        'one run of 133 charged days from Mon 2026-04-06, no holiday, M = 22',
        'day 1 = 6 April; day n = 6 April + 7 x intdiv(n-1, 5) + (n-1) mod 5 days',
        'day 22: +28 +1 = 5 May, full. Day 23: 6 May, half',
        'day 66: +91 = 6 July, half. Day 67: 7 July, unpaid',
        'day 132: +182 +1 = 6 October, unpaid. Day 133: 7 October, beyond',
        'tiers: days 1-22 full, 23-66 half, 67-132 unpaid, 133 beyond',
    ], [$ap('R1', '2026-04-06', '2026-10-07', 133)], ['tiers' => [['m' => 22, 'dates' => [
        '2026-04-06' => 'full', '2026-05-05' => 'full', '2026-05-06' => 'half', '2026-07-06' => 'half',
        '2026-07-07' => 'unpaid', '2026-10-06' => 'unpaid', '2026-10-07' => 'beyond',
    ]]]]),

    $c('GL-SICK-002', ['LVR-SICK-04', 'LVR-SICK-05'], 'register', '3, 4', [
        '22 sick days 1 to 30 April 2026 (Wed 1 April to Thu 30 April: 3 + 5 + 5 + 5 + 4 = 22), first day 2026-04-01',
        'the period ends 2026-04-01 + 12 months - 1 day = 2027-03-31, that day inside',
        'Wed 2027-03-31 is the 23rd day of the period: half pay',
        'Thu 2027-04-01 starts a new period: full pay',
        'reading B (rolling twelve months back): 2 April 2026 to 1 April 2027 holds 21 April days + 31 March = 22, so 1 April 2027 is day 23: half',
    ], [$ap('R1', '2026-04-01', '2026-04-30', 22), $ap('R2', '2027-03-31', '2027-03-31', 1), $ap('R3', '2027-04-01', '2027-04-01', 1)], [
        'tiers' => [['m' => 22, 'dates' => ['2026-04-30' => 'full', '2027-03-31' => 'half', '2027-04-01' => 'full']]],
    ], ['readings' => ['B' => ['tiers' => [['m' => 22, 'dates' => ['2026-04-30' => 'full', '2027-03-31' => 'half', '2027-04-01' => 'half']]]]]]),

    $c('GL-SICK-003', ['LVR-SICK-04', 'LVR-SICK-05'], 'register (item 3 example)', '3, 4', [
        '22 days 1 to 30 April 2026; 22 days Mon 1 to Tue 30 March 2027 (20 + 2); then 5 days Mon 2027-04-05 to Fri 2027-04-09',
        'reading A (the code): the first period ran 2026-04-01 to 2027-03-31; March days are days 23 to 44: half',
        'the April 2027 days open a new period: full pay',
        'reading B: 12 months back from 2027-04-05 hold 19 + 22 = 41 days, so the April 2027 days are days 42 to 46: half',
    ], [$ap('R1', '2026-04-01', '2026-04-30', 22), $ap('R2', '2027-03-01', '2027-03-30', 22), $ap('R3', '2027-04-05', '2027-04-09', 5)], [
        'tiers' => [['m' => 22, 'dates' => ['2027-03-01' => 'half', '2027-03-30' => 'half', '2027-04-05' => 'full', '2027-04-09' => 'full']]],
    ], ['readings' => ['B' => ['tiers' => [['m' => 22, 'dates' => ['2027-03-01' => 'half', '2027-03-30' => 'half', '2027-04-05' => 'half', '2027-04-09' => 'half']]]]]]),

    $c('GL-SICK-004', 'LVR-SICK-04', 'register', '4', [
        'first day 2028-02-29, 22 charged days to Wed 2028-03-29 (1 + 3 + 5 + 5 + 5 + 3)',
        'the period ends 2028-02-29 + 12 months - 1 day = 2029-02-28 (run on 2026-11-28 per the register)',
        'Wed 2029-02-28 is day 23: half. Thu 2029-03-01 opens a new period: full',
    ], [$ap('R1', '2028-02-29', '2028-03-29', 22), $ap('R2', '2029-02-28', '2029-02-28', 1), $ap('R3', '2029-03-01', '2029-03-01', 1)], [
        'tiers' => [['m' => 22, 'dates' => ['2028-03-29' => 'full', '2029-02-28' => 'half', '2029-03-01' => 'full']]],
    ]),

    $c('GL-SICK-005', 'LVR-SICK-09', 'register', '4', [
        'a first period with 110 approved charged days (Mon 2026-04-06 to Fri 2026-09-04)',
        'a further 30-day request (Mon 2026-09-07 to Fri 2026-10-16) is days 111 to 140: beyond 132, refused',
        'a 20-day request (Mon 2026-09-07 to Fri 2026-10-02) is days 111 to 130: accepted, stored 20',
    ], [$ap('R1', '2026-04-06', '2026-09-04', 110)], [
        'after' => [['refused' => true], ['refused' => false, 'requests' => ['R3' => ['days' => 20]]]],
    ], [
        'now' => '2026-09-01 09:00',
        'act' => [
            ['do' => 'file', 'key' => 'R2', 'from' => '2026-09-07', 'to' => '2026-10-16', 'by' => 'hr'],
            ['do' => 'file', 'key' => 'R3', 'from' => '2026-09-07', 'to' => '2026-10-02', 'by' => 'hr'],
        ],
    ]),

    $c('GL-SICK-006', ['LVR-SICK-13', 'LVR-SICK-03'], 'register', '4', [
        '30 charged days Mon 2026-04-06 to Fri 2026-05-15',
        'M = 22: days 1-22 full (day 22 = 5 May), day 23 = 6 May half',
        'the organisation sets 24: days 1-24 full (day 24 = 7 May), day 25 = 8 May half; the same days are re-tiered',
    ], [$ap('R1', '2026-04-06', '2026-05-15', 30)], ['tiers' => [
        ['m' => 22, 'dates' => ['2026-05-05' => 'full', '2026-05-06' => 'half', '2026-05-15' => 'half']],
        ['m' => 24, 'dates' => ['2026-05-07' => 'full', '2026-05-08' => 'half', '2026-05-15' => 'half']],
    ]]),
];

<?php

declare(strict_types=1);

/*
 * Carry-over (LVR-CARRY-01 to LVR-CARRY-05, LVR-BAL-01). DATA ONLY.
 *
 * The carry job runs at 01:00 UTC on the first day of 2026. Remaining =
 * entitled + carried - used - pending (LVR-BAL-01). `expect.after` has one
 * entry per act: the balance rows named, by leave-year number. A row that
 * is not there reads as zero.
 */

$carry = fn (string $at = '2026-01-01 01:00'): array => ['at' => $at, 'do' => 'carry', 'from' => 2025, 'to' => 2026];
$row = fn (int $entitled, int $used, int $carried = 0, int $pending = 0): array => [
    'year' => 2025, 'entitled_tenths' => $entitled, 'used_tenths' => $used, 'carried_tenths' => $carried, 'pending_tenths' => $pending,
];

$c = fn (string $id, array|string $rule, string $source, array $working, array $balances, array $type, array $act, array $after, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'type' => ['carry_forward' => true, ...$type],
    'balances' => $balances,
    'act' => $act,
    'expect' => ['after' => $after],
    ...$extra,
];

return [
    $c('GL-CARRY-001', ['LVR-CARRY-01', 'LVR-BAL-01'], 'register', [
        '2025: entitled 20, used 8, cap 5',
        'remaining 20 + 0 - 8 - 0 = 12',
        'carried into 2026 = the smaller of remaining 12 and the cap 5 = 5',
    ], [$row(200, 80)], ['max_carry_days' => 5], [$carry()], [
        ['balance' => [2026 => ['carried_tenths' => 50], 2025 => ['remaining_tenths' => 120]]],
    ]),

    $c('GL-CARRY-002', 'LVR-CARRY-01', 'register', [
        '2025: entitled 20, used 8, no cap: all of remaining 12 is carried',
    ], [$row(200, 80)], ['max_carry_days' => null], [$carry()], [
        ['balance' => [2026 => ['carried_tenths' => 120], 2025 => ['remaining_tenths' => 120]]],
    ]),

    $c('GL-CARRY-003', ['LVR-CARRY-01', 'LVR-CARRY-05'], 'register', [
        '2025: entitled 16, used 0, pending 5 on 31 December, cap 16',
        'remaining 16 - 0 - 5 = 11; carried = smaller of 11 and 16 = 11',
        'rejected on 10 January: the 2025 balance gives the 5 days back, remaining 16',
        'the run of days 1 to 7 is over, so 2026\'s carried stays 11',
    ], [$row(160, 0, 0, 50)], ['max_carry_days' => 16], [
        $carry(),
        ['at' => '2026-01-10 09:00', 'do' => 'reject', 'key' => 'R1', 'by' => 'hr'],
    ], [
        ['balance' => [2026 => ['carried_tenths' => 110], 2025 => ['remaining_tenths' => 110]]],
        ['balance' => [2026 => ['carried_tenths' => 110], 2025 => ['remaining_tenths' => 160, 'pending_tenths' => 0]]],
    ], [
        'requests' => [['key' => 'R1', 'from' => '2025-12-22', 'to' => '2025-12-26', 'days' => 5, 'status' => 'pending', 'year' => 2025]],
    ]),

    $c('GL-CARRY-004', 'LVR-CARRY-01', 'register', [
        'the carry is assigned, not added, so a repeat changes nothing',
        '2025: entitled 20, used 8, cap 5: 5 after the first run and 5 after the second',
    ], [$row(200, 80)], ['max_carry_days' => 5], [$carry(), $carry('2026-01-02 01:00')], [
        ['balance' => [2026 => ['carried_tenths' => 50]]],
        ['balance' => [2026 => ['carried_tenths' => 50]]],
    ]),

    $c('GL-CARRY-005', 'LVR-CARRY-01', 'register', [
        '2025: entitled 20, used 18: remaining 2, cap 5',
        'carried = the smaller of 2 and 5 = 2',
    ], [$row(200, 180)], ['max_carry_days' => 5], [$carry()], [
        ['balance' => [2026 => ['carried_tenths' => 20], 2025 => ['remaining_tenths' => 20]]],
    ]),

    $c('GL-CARRY-006', 'LVR-CARRY-01', 'register', [
        'only a balance with remaining ABOVE zero is carried',
        '2025: entitled 16, used 16: remaining 0: nothing is carried',
    ], [$row(160, 160)], ['max_carry_days' => 16], [$carry()], [
        ['balance' => [2026 => ['carried_tenths' => 0], 2025 => ['remaining_tenths' => 0]]],
    ]),

    $c('GL-CARRY-007', 'LVR-CARRY-01', 'register', [
        'only a type with carry forward on is carried',
        '2025: entitled 16, used 0 on a type with carry forward off: nothing is carried',
    ], [$row(160, 0)], ['carry_forward' => false, 'max_carry_days' => 16], [$carry()], [
        ['balance' => [2026 => ['carried_tenths' => 0], 2025 => ['remaining_tenths' => 160]]],
    ]),

    // LVR-CARRY-03 is undecided: typed from the register's reading A, never asserted.
    $c('GL-CARRY-008', 'LVR-CARRY-03', 'register', [
        'carried days never expire and are carried again: 2025 entitled 16, carried 16, used 0',
        'remaining 16 + 16 - 0 = 32; with the cap of 16 the carry into 2026 is 16',
    ], [$row(160, 0, 160)], ['max_carry_days' => 16], [$carry()], [
        ['balance' => [2026 => ['carried_tenths' => 160], 2025 => ['remaining_tenths' => 320]]],
    ], ['undecided' => true]),

    $c('GL-CARRY-009', 'LVR-CARRY-03', 'register', [
        'the same balance with no cap: all of remaining 32 is carried',
    ], [$row(160, 0, 160)], ['max_carry_days' => null], [$carry()], [
        ['balance' => [2026 => ['carried_tenths' => 320], 2025 => ['remaining_tenths' => 320]]],
    ], ['undecided' => true]),
];

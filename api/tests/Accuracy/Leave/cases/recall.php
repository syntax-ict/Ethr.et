<?php

declare(strict_types=1);

/*
 * Cancelling and ending approved leave early (LVR-RECALL-01 to LVR-RECALL-04,
 * LVR-DAYS-06). DATA ONLY.
 *
 * R1 is approved, Mon 2026-12-07 to Fri 2026-12-11, stored 5 days, entitled 16,
 * used 5. The clock stands at 2026-12-08 09:00 UTC. HR acts. `kept_days` is
 * the days counted again up to the new last day.
 */

$bal = ['year' => 2026, 'entitled_tenths' => 160, 'used_tenths' => 50, 'carried_tenths' => 0, 'pending_tenths' => 0];
$r1 = ['key' => 'R1', 'from' => '2026-12-07', 'to' => '2026-12-11', 'days' => 5, 'status' => 'approved'];
$end = fn (string $date): array => ['do' => 'recall', 'key' => 'R1', 'end' => $date, 'by' => 'hr'];

$c = fn (string $id, array|string $rule, string $source, array $working, array $act, array $after, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'working' => $working,
    'now' => '2026-12-08 09:00',
    'balances' => [$bal],
    'requests' => [$r1],
    'act' => $act,
    'expect' => ['after' => $after],
    ...$extra,
];

return [
    $c('GL-RECALL-001', 'LVR-RECALL-01', 'register', [
        'an approved 5-day request is cancelled by HR: it becomes cancelled, its stored 5 days come off used',
        'used 5 - 5 = 0, remaining 16',
    ], [['do' => 'cancel', 'key' => 'R1', 'by' => 'hr']], [
        ['refused' => false, 'requests' => ['R1' => ['status' => 'cancelled']], 'balance' => [2026 => ['used_tenths' => 0, 'remaining_tenths' => 160]]],
    ]),

    $c('GL-RECALL-002', 'LVR-RECALL-02', 'register', [
        'Monday to Friday ended on Wednesday 2026-12-09: kept Mon, Tue, Wed = 3',
        'stored 5 - kept 3 = 2 given back: used 5 - 2 = 3, remaining 13',
    ], [$end('2026-12-09')], [
        ['refused' => false, 'kept_days' => 3, 'balance' => [2026 => ['used_tenths' => 30, 'remaining_tenths' => 130]]],
    ]),

    $c('GL-RECALL-003', ['LVR-DAYS-06', 'LVR-RECALL-02'], 'register', [
        'HR adds a holiday on the Wednesday 2026-12-09 after the request was stored with 5',
        'the stored number does not change: R1 still 5, used 5',
        'ended on Thursday 2026-12-10: kept Mon, Tue, Thu = 3 (Wednesday is a holiday), 2 given back: used 3',
    ], [['do' => 'add_holiday', 'date' => '2026-12-09'], $end('2026-12-10')], [
        ['requests' => ['R1' => ['days' => 5]], 'balance' => [2026 => ['used_tenths' => 50]]],
        ['refused' => false, 'kept_days' => 3, 'balance' => [2026 => ['used_tenths' => 30, 'remaining_tenths' => 130]]],
    ]),

    $c('GL-RECALL-004', ['LVR-DAYS-06', 'LVR-RECALL-01'], 'register', [
        'a holiday added on Wednesday 2026-12-09 after the request was stored with 5',
        'HR cancels it: the stored 5 days come off used, not the 4 counted today: used 0',
    ], [['do' => 'add_holiday', 'date' => '2026-12-09'], ['do' => 'cancel', 'key' => 'R1', 'by' => 'hr']], [
        ['requests' => ['R1' => ['days' => 5]]],
        ['refused' => false, 'requests' => ['R1' => ['status' => 'cancelled']], 'balance' => [2026 => ['used_tenths' => 0, 'remaining_tenths' => 160]]],
    ]),

    $c('GL-RECALL-005', 'LVR-RECALL-02', 'register', [
        'the new last day must be BEFORE the current last day: ending on Friday 2026-12-11 is refused',
        'used stays 5',
    ], [$end('2026-12-11')], [
        ['refused' => true, 'balance' => [2026 => ['used_tenths' => 50]]],
    ]),

    $c('GL-RECALL-006', 'LVR-RECALL-02', 'register', [
        'the new last day must be on or after the first day: ending on Sunday 2026-12-06 is refused',
        'used stays 5',
    ], [$end('2026-12-06')], [
        ['refused' => true, 'balance' => [2026 => ['used_tenths' => 50]]],
    ]),
];

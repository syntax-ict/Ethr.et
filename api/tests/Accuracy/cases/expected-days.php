<?php

declare(strict_types=1);

/*
 * What a day is for a person who has no record, read as the timesheet ledger
 * reads it. S1, Tuesday 2026-10-13, read the next morning at 10:00 unless a
 * case says otherwise. A second person's record that day makes the
 * organisation one that records attendance (AR-ABS-05). DATA ONLY.
 */

$d = '2026-10-13';

$case = fn (string $id, array|string $rule, string $source, array $day, array $extra = [], string $date = '2026-10-13'): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'shift' => 'S1',
    'punches' => [],
    'now' => '+1 10:00',
    'setup' => ['peer' => true],
    'expect' => ['live' => 0, 'days' => [$date => $day]],
    ...$extra,
];

return [
    $case('GC-ABS-001', ['AR-ABS-04', 'AR-ABS-01'], '05 ABS-001', ['class' => 'workday', 'code' => 'A']),
    $case('GC-ABS-002', ['AR-ABS-01', 'AR-ABS-03'], '05 ABS-001', ['class' => 'holiday', 'code' => 'H'], ['setup' => ['peer' => true, 'holiday' => true]]),
    $case('GC-ABS-003', ['AR-ABS-01', 'AR-ABS-02'], '05 ABS-001', ['class' => 'rest_day', 'code' => 'R'], ['day' => '2026-10-17', 'setup' => ['peer' => '2026-10-17']], '2026-10-17'),
    $case('GC-ABS-004', ['AR-ABS-01'], '05 ABS-001', ['class' => 'on_leave', 'code' => 'AL'], ['setup' => ['peer' => true, 'leave' => ['2026-10-13', '2026-10-13']]]),
    $case('GC-ABS-005', ['AR-ABS-01'], 'register', ['class' => 'not_employed', 'code' => "\u{2013}"], ['setup' => ['peer' => true, 'hire_date' => '2026-10-14']]),
    $case('GC-ABS-006', ['AR-ABS-04'], 'register', ['class' => 'workday', 'code' => null], ['now' => '10:00']),
    $case('GC-ABS-007', ['AR-ABS-03', 'AR-ABS-04'], '05 HOL-003', ['class' => 'workday', 'code' => 'A'], ['setup' => ['peer' => true, 'holiday_elsewhere' => true]]),
    $case('GC-ABS-008', ['AR-ABS-05'], 'register', ['class' => 'workday', 'code' => null], ['setup' => []]),
];

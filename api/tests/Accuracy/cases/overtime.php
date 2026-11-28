<?php

declare(strict_types=1);

/*
 * Overtime buckets and the day's class, read as the timesheet ledger reads
 * them. Tuesday 2026-10-13 unless a case names another day. DATA ONLY.
 */

$d = '2026-10-13';

$case = fn (string $id, array|string $rule, string $source, ?string $shift, array $punches, array $day, array $extra = [], string $date = '2026-10-13'): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'shift' => $shift,
    'punches' => $punches,
    'expect' => ['days' => [$date => $day]],
    ...$extra,
];

$in = fn (string $at, string $method = 'web'): array => ['type' => 'in', 'at' => $at, 'method' => $method];
$out = fn (string $at, string $method = 'web'): array => ['type' => 'out', 'at' => $at, 'method' => $method];

return [
    $case('GC-OT-001', 'AR-OT-01', '07 PAY-031', 'S1', [$in('08:40'), $out('19:00')], ['live' => 1, 'status' => 'present', 'overtime' => ['normal' => 90], 'class' => 'workday']),
    $case('GC-OT-002', 'AR-OT-01', 'register', 'S1', [$in('08:30'), $out('17:30')], ['live' => 1, 'overtime' => []]),
    $case('GC-OT-003', ['AR-OT-01', 'AR-OT-09'], 'register', 'S1', [$in('08:30'), $out('17:31')], ['live' => 1, 'overtime' => ['normal' => 1]]),
    $case('GC-OT-004', ['AR-OT-01', 'AR-OT-03'], 'register', 'S1', [$in('08:30'), $out('23:00')], ['live' => 1, 'overtime' => ['normal' => 270, 'night' => 60]]),
    $case('GC-OT-005', ['AR-OT-01', 'AR-NIGHT-01'], 'register', 'S2', [$in('21:58'), $out('+1 07:30')], ['live' => 1, 'overtime' => ['normal' => 90], 'class' => 'workday']),
    $case('GC-OT-006', ['AR-OT-02', 'AR-ABS-03'], 'register', 'S3', [$in('08:00'), $out('17:00')], ['live' => 1, 'overtime' => ['holiday' => 540], 'class' => 'holiday', 'code' => 'H'], ['setup' => ['holiday' => true]]),
    $case('GC-OT-007', ['AR-OT-02', 'AR-ABS-02'], 'register', 'S1', [$in('09:00'), $out('13:00')], ['live' => 1, 'overtime' => ['rest_day' => 240], 'class' => 'rest_day', 'code' => 'R'], ['day' => '2026-10-17'], '2026-10-17'),
    $case('GC-OT-008', ['AR-OT-01', 'AR-MIN-01', 'AR-STATUS-01'], 'register', 'S1', [$in('09:10'), $out('19:00')], ['live' => 1, 'status' => 'late', 'late' => 40, 'overtime' => ['normal' => 90]]),
    $case('GC-OT-009', ['AR-OT-02', 'AR-ABS-03'], 'register', 'S3', [$in('08:00'), $out('17:00')], ['live' => 1, 'overtime' => [], 'class' => 'workday'], ['setup' => ['holiday_elsewhere' => true]]),
];

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

    // Overtime is time worked after the shift's end (AR-OT-01), so a record
    // that begins after the end counts from its own check-in. Added
    // 2026-11-28 when the register was made computable for the oracle.
    $case('GC-OT-010', 'AR-OT-01', '05 PUNCH-012', 'S1', [$in('08:25'), $out('16:00'), $in('17:45'), $out('20:00')], ['live' => 2, 'overtime' => ['normal' => 135]]),
    $case('GC-OT-011', 'AR-OT-01', 'register', 'S1', [$in('18:00'), $out('20:00')], ['live' => 1, 'overtime' => ['normal' => 120]]),

    // The night window's two edges (AR-OT-03).
    $case('GC-OT-012', 'AR-OT-03', 'register', 'S1', [$in('08:30'), $out('22:01')], ['live' => 1, 'overtime' => ['normal' => 270, 'night' => 1]]),
    $case('GC-OT-013', ['AR-OT-03', 'AR-NIGHT-01'], 'register', 'S2', [$in('21:58'), $out('+1 06:01')], ['live' => 1, 'overtime' => ['normal' => 1]]),

    // A rest day's night is rest day time (AR-OT-12); a holiday on a rest day is a holiday (AR-OT-11).
    $case('GC-OT-014', ['AR-OT-12', 'AR-OT-02'], 'register', 'S1', [$in('18:00'), $out('23:00')], ['live' => 1, 'overtime' => ['rest_day' => 300], 'class' => 'rest_day'], ['day' => '2026-10-17'], '2026-10-17'),
    $case('GC-OT-015', ['AR-OT-11', 'AR-OT-02'], 'register', 'S1', [$in('09:00'), $out('13:00')], ['live' => 1, 'overtime' => ['holiday' => 240], 'class' => 'holiday'], ['day' => '2026-10-17', 'setup' => ['holiday' => true]], '2026-10-17'),

    // Nobody is late or early on a day that is not a working day (AR-MIN-06),
    // on the record as on the timesheet: payroll's reconciliation reads the
    // record. Added 2026-11-28 after the generated run (BASELINE 25bt).
    $case('GC-REST-001', ['AR-MIN-06', 'AR-OT-02'], 'register', 'S1', [$in('09:00'), $out('13:00')], ['live' => 1, 'late' => 0, 'early' => 0, 'overtime' => ['rest_day' => 240], 'class' => 'rest_day'], ['day' => '2026-10-17'], '2026-10-17'),
    $case('GC-REST-002', ['AR-MIN-06', 'AR-OT-02'], 'register', 'S1', [$in('09:00'), $out('13:00')], ['live' => 1, 'late' => 0, 'early' => 0, 'overtime' => ['holiday' => 240], 'class' => 'holiday'], ['setup' => ['holiday' => true]]),
];

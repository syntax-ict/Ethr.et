<?php

declare(strict_types=1);

/*
 * Status and minutes of one live day on S1 (08:30 to 17:30, grace 15, early
 * departure 15, break 60, Monday to Friday), Tuesday 2026-10-13. Every value
 * is typed from docs/ATTENDANCE-RULES.md or the QA pack case named in
 * `source`. DATA ONLY: nothing here computes anything.
 */

$d = '2026-10-13';

$case = fn (string $id, array|string $rule, string $source, array $punches, array $day, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'shift' => 'S1',
    'punches' => $punches,
    'expect' => ['days' => [$d => $day]],
    ...$extra,
];

$in = fn (string $at, string $method = 'web'): array => ['type' => 'in', 'at' => $at, 'method' => $method];
$out = fn (string $at, string $method = 'web'): array => ['type' => 'out', 'at' => $at, 'method' => $method];
$manual = fn (string $in, string $out, string $method = 'manual'): array => ['type' => 'manual', 'in' => $in, 'out' => $out, 'method' => $method];

return [
    // Grace boundary.
    $case('GC-STATUS-001', ['AR-STATUS-01', 'AR-MIN-01'], '05 ATT-001', [$in('08:40')], ['live' => 1, 'status' => 'present', 'late' => 0, 'check_in' => '08:40']),
    $case('GC-STATUS-002', ['AR-STATUS-01', 'AR-MIN-01'], '05 ATT-002', [$in('08:46')], ['live' => 1, 'status' => 'late', 'late' => 16]),
    $case('GC-STATUS-003', 'AR-STATUS-01', '05 ATT-003', [$in('08:45:00')], ['live' => 1, 'status' => 'present', 'late' => 0]),
    $case('GC-STATUS-004', ['AR-STATUS-01', 'AR-MIN-01'], 'register', [$in('08:46:00')], ['live' => 1, 'status' => 'late', 'late' => 16]),
    $case('GC-STATUS-005', 'AR-STATUS-07', 'register', [$in('08:45:30')], ['live' => 1, 'status' => 'present'], ['undecided' => true]),
    $case('GC-STATUS-006', 'AR-STATUS-01', 'register', [$in('08:30:00')], ['live' => 1, 'status' => 'present', 'late' => 0]),
    $case('GC-STATUS-007', 'AR-STATUS-01', 'register', [$in('08:44')], ['live' => 1, 'status' => 'present', 'late' => 0]),
    $case('GC-STATUS-008', ['AR-STATUS-01', 'AR-MIN-07'], 'register', [$in('08:00')], ['live' => 1, 'status' => 'present', 'late' => 0, 'check_in' => '08:00']),
    $case('GC-STATUS-009', 'AR-MIN-01', 'register', [$in('09:00')], ['live' => 1, 'status' => 'late', 'late' => 30]),
    $case('GC-MIN-001', ['AR-CLOCK-04', 'AR-MIN-01'], 'register', [$in('08:46:59')], ['live' => 1, 'status' => 'late', 'late' => 16]),

    // Early leave, arrived 08:30.
    $case('GC-EARLY-001', ['AR-STATUS-03', 'AR-MIN-02'], '05 ATT-005', [$in('08:30'), $out('17:10')], ['live' => 1, 'status' => 'early_leave', 'early' => 20, 'late' => 0]),
    $case('GC-EARLY-002', 'AR-STATUS-03', 'register', [$in('08:30'), $out('17:15')], ['live' => 1, 'status' => 'present', 'early' => 0]),
    $case('GC-EARLY-003', ['AR-STATUS-03', 'AR-MIN-02'], 'register', [$in('08:30'), $out('17:14')], ['live' => 1, 'status' => 'early_leave', 'early' => 16]),
    $case('GC-EARLY-004', 'AR-STATUS-03', '05 ATT-004', [$in('08:30'), $out('17:35')], ['live' => 1, 'status' => 'present', 'early' => 0, 'check_out' => '17:35']),
    $case('GC-EARLY-005', ['AR-STATUS-04', 'AR-MIN-03'], '05 PUNCH-010', [$in('08:50', 'mobile'), $out('16:00', 'mobile')], ['live' => 1, 'status' => 'late', 'late' => 20, 'early' => 90]),

    // 07 LATE-004: a manual day is rated as a live one (AR-STATUS-05).
    $case('GC-LATE4-001', ['AR-STATUS-05', 'AR-MIN-01', 'AR-MIN-03'], '07 LATE-004', [$manual('08:46', '17:30')], ['live' => 1, 'status' => 'late', 'late' => 16, 'early' => 0]),
    $case('GC-LATE4-002', ['AR-STATUS-05', 'AR-MIN-02', 'AR-MIN-03'], '07 LATE-004', [$manual('08:35', '17:10')], ['live' => 1, 'status' => 'early_leave', 'late' => 0, 'early' => 20]),
    $case('GC-LATE4-003', ['AR-STATUS-04', 'AR-STATUS-05', 'AR-MIN-03'], '07 LATE-004', [$manual('08:46', '17:10')], ['live' => 1, 'status' => 'late', 'late' => 16, 'early' => 20]),

    // Worked minutes.
    $case('GC-WORK-001', 'AR-MIN-04', 'register', [$in('08:30'), $out('17:30')], ['live' => 1, 'worked' => 480]),
    $case('GC-WORK-002', ['AR-MIN-04', 'AR-CLOCK-04'], 'register', [$in('08:30:00'), $out('17:30:59')], ['live' => 1, 'worked' => 480]),
    $case('GC-WORK-003', 'AR-MIN-05', 'register', [$in('08:30')], ['live' => 1, 'worked' => 0, 'check_out' => null]),

    // A manual or imported row is rated like a punch (05 CAP-002).
    $case('GC-CHAN-004', ['AR-STATUS-05', 'AR-MIN-01'], '05 CAP-002', [$manual('08:50', '17:30', 'csv')], ['live' => 1, 'status' => 'late', 'late' => 20]),

    // The same ATT-002 case in other zones: same wall-clock times, same answer.
    $case('GC-ZONE-001', ['AR-CLOCK-01', 'AR-STATUS-01'], '05 ATT-002', [$in('08:46')], ['live' => 1, 'status' => 'late', 'late' => 16], ['zone' => 'Asia/Dubai']),
    $case('GC-ZONE-002', ['AR-CLOCK-01', 'AR-STATUS-01'], '05 ATT-003', [$in('08:45:00')], ['live' => 1, 'status' => 'present', 'late' => 0], ['zone' => 'Asia/Dubai']),
    $case('GC-ZONE-003', ['AR-CLOCK-01', 'AR-STATUS-01'], '05 ATT-002', [$in('08:46')], ['live' => 1, 'status' => 'late', 'late' => 16], ['zone' => 'America/Bogota']),

    // A punch at 01:30 is dated that local day (05 ATT-008).
    $case('GC-DATE-001', 'AR-CLOCK-02', '05 ATT-008', [$in('01:30')], ['live' => 1]),

    // No shift at all (05 ATT-009): pending, nothing late, nothing early, no break taken off.
    [
        'id' => 'GC-SHIFT-001',
        'rule' => ['AR-SHIFT-06', 'AR-MIN-05', 'AR-OT-08'],
        'source' => '05 ATT-009',
        'shift' => null,
        'punches' => [$in('08:00'), $out('17:00')],
        'expect' => ['days' => [$d => ['live' => 1, 'status' => 'pending', 'late' => 0, 'early' => 0, 'worked' => 540, 'overtime' => [], 'class' => 'workday']]],
    ],
];

<?php

declare(strict_types=1);

/*
 * One record a day, breaks inside the day, duplicates, conflicts and
 * automatic punches, on S1, Tuesday 2026-10-13. DATA ONLY.
 */

$d = '2026-10-13';

$case = fn (string $id, array|string $rule, string $source, array $punches, array $expect, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'shift' => 'S1',
    'punches' => $punches,
    'expect' => $expect,
    ...$extra,
];

$in = fn (string $at, string $method = 'web'): array => ['type' => 'in', 'at' => $at, 'method' => $method];
$out = fn (string $at, string $method = 'web'): array => ['type' => 'out', 'at' => $at, 'method' => $method];
$auto = fn (string $at, string $method = 'kiosk'): array => ['type' => 'auto', 'at' => $at, 'method' => $method];
$manual = fn (string $in, string $out, string $method = 'manual'): array => ['type' => 'manual', 'in' => $in, 'out' => $out, 'method' => $method];

return [
    // A break inside the day (05 PUNCH-011, PUNCH-012).
    $case('GC-BREAK-001', ['AR-BREAK-01', 'AR-BREAK-03', 'AR-MIN-04', 'AR-MIN-01'], '05 PUNCH-011', [$in('08:50'), $out('12:05'), $in('13:55'), $out('17:00')], [
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'status' => 'late', 'late' => 20, 'worked' => 430, 'check_in' => '08:50', 'check_out' => '17:00', 'breaks' => ['out', 'in']]],
    ]),
    $case('GC-BREAK-002', 'AR-BREAK-01', '05 PUNCH-011', [$in('08:25'), $out('10:00'), $in('13:00')], [
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'check_in' => '08:25', 'check_out' => null]],
    ]),
    $case('GC-BREAK-003', 'AR-BREAK-02', '05 PUNCH-012', [$in('08:25'), $out('10:00'), $in('13:01')], [
        'conflicts' => 1,
        'days' => [$d => ['live' => 2]],
    ]),
    $case('GC-BREAK-004', 'AR-BREAK-02', '05 PUNCH-012', [$in('08:25'), $out('16:00'), $in('17:45')], [
        'conflicts' => 1,
        'days' => [$d => ['live' => 2]],
    ]),
    $case('GC-BREAK-005', ['AR-BREAK-03', 'AR-MIN-04'], 'register', [$in('08:25'), $out('12:00'), $in('13:00'), $out('17:30')], [
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'status' => 'present', 'early' => 0, 'worked' => 485, 'check_in' => '08:25', 'check_out' => '17:30']],
    ]),

    // Duplicates and the same arrival seen twice.
    $case('GC-DAY-001', ['AR-DAY-02'], '05 PUNCH-008', [$in('08:25', 'mobile'), $in('08:50', 'kiosk')], [
        'outcomes' => ['ok', 'duplicate'],
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'status' => 'present', 'check_in' => '08:25']],
    ]),
    $case('GC-DAY-002', ['AR-DAY-04'], '05 PUNCH-007', [$in('08:25'), $out('17:30', 'kiosk'), $out('17:32', 'mobile')], [
        'outcomes' => ['ok', 'ok', 'duplicate'],
        'days' => [$d => ['live' => 1, 'check_out' => '17:30']],
    ]),
    $case('GC-DAY-003', ['AR-DAY-05'], '05 PUNCH-007', [$in('08:25'), $out('17:30', 'kiosk'), $out('18:00', 'mobile')], [
        'outcomes' => ['ok', 'ok', 'refused:no_open_check_in'],
        'days' => [$d => ['live' => 1, 'check_out' => '17:30']],
    ]),
    $case('GC-DAY-004', ['AR-DAY-05'], '05 ATT-006', [$out('17:30')], [
        'outcomes' => ['refused:no_open_check_in'],
        'live' => 0,
    ]),
    // Two methods within 5 minutes are merged (05 CON-002: "earliest check-in
    // and latest check-out kept, the other voided, no conflict"). No document
    // says what the second punch is answered with, so no outcome is asserted
    // here: a first draft typed `duplicate` with no source, and that value was
    // withdrawn at review on 2026-11-28 (BASELINE 25bp), before any figure
    // was reported from it.
    $case('GC-DAY-005', ['AR-DAY-03'], '05 CON-002', [$in('08:25', 'mobile'), $in('08:28', 'kiosk')], [
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'check_in' => '08:25']],
    ]),
    $case('GC-DAY-006', ['AR-DAY-03'], 'register', [$in('08:25', 'mobile'), $in('08:30', 'kiosk')], [
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'check_in' => '08:25']],
    ]),
    $case('GC-DAY-007', ['AR-DAY-02'], 'register', [$in('08:25', 'mobile'), $in('08:31', 'kiosk')], [
        'outcomes' => ['ok', 'duplicate'],
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'check_in' => '08:25']],
    ]),
    $case('GC-DAY-008', ['AR-DAY-07'], '05 CON-001', [$in('08:30'), $manual('08:45', '17:30')], [
        'conflicts' => 1,
        'days' => [$d => ['live' => 2]],
    ]),
    $case('GC-DAY-009', ['AR-DAY-03', 'AR-DAY-07'], '05 MAN-003', [$in('08:30'), $manual('08:33', '17:30')], [
        'conflicts' => 0,
        'days' => [$d => ['live' => 1, 'check_in' => '08:30', 'check_out' => '17:30']],
    ]),
    $case('GC-DAY-010', ['AR-DAY-01'], '05 ATT-007', [
        ['type' => 'in', 'at' => '08:25', 'method' => 'web', 'key' => 'retry-1'],
        ['type' => 'in', 'at' => '08:25', 'method' => 'web', 'key' => 'retry-1'],
    ], [
        'outcomes' => ['ok', 'duplicate'],
        'days' => [$d => ['live' => 1]],
    ]),
    $case('GC-DAY-011', ['AR-DAY-06'], '05 PUNCH-004', [$auto('08:25'), $auto('08:27'), $auto('17:30')], [
        'outcomes' => ['ok', 'duplicate', 'ok'],
        'days' => [$d => ['live' => 1, 'check_in' => '08:25', 'check_out' => '17:30']],
    ]),

    // Automatic check-out at the end of the shift (05 AUTO-001, AUTO-002).
    $case('GC-AUTO-001', ['AR-AUTO-01', 'AR-MIN-04'], '05 AUTO-001', [$in('08:30')], [
        'days' => [$d => ['live' => 1, 'check_out' => '17:30', 'worked' => 480, 'metadata' => ['auto_checkout' => true]]],
    ], ['setup' => ['auto_checkout' => 120], 'auto_run_at' => '19:35']),
    $case('GC-AUTO-002', 'AR-AUTO-01', 'register', [$in('08:30')], [
        'days' => [$d => ['live' => 1, 'check_out' => null]],
    ], ['setup' => ['auto_checkout' => 120], 'auto_run_at' => '19:29']),
    $case('GC-AUTO-003', 'AR-AUTO-02', '05 AUTO-002', [$in('18:00')], [
        'days' => [$d => ['live' => 1, 'check_out' => null]],
    ], ['setup' => ['auto_checkout' => 120], 'auto_run_at' => '20:35']),

    // Added 2026-11-28 after the independent review of the oracle (BASELINE 25bq).
    // Whether a check-in exactly at the shift's end is "after" it is not stated
    // (AR-NIGHT-05). The first draft asserted "closed at 17:30" from a literal
    // reading; the engine and the oracle both leave it open. Kept, undecided.
    $case('GC-AUTO-004', ['AR-NIGHT-05', 'AR-AUTO-02'], 'register', [$in('17:30:00')], [
        'days' => [$d => ['live' => 1, 'check_out' => '17:30']],
    ], ['setup' => ['auto_checkout' => 120], 'auto_run_at' => '20:00', 'undecided' => true]),
    // A record left open overnight does not absorb the next morning's arrival (AR-DAY-09).
    $case('GC-DAY-012', 'AR-DAY-09', 'register', [$in('19:00'), $in('+1 08:25')], [
        'outcomes' => ['ok', 'ok'],
        'live' => 2,
        'days' => [
            $d => ['live' => 1, 'check_in' => '19:00', 'check_out' => null],
            '2026-10-14' => ['live' => 1, 'status' => 'present', 'check_in' => '08:25'],
        ],
    ]),
];

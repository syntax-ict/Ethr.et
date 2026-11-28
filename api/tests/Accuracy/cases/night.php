<?php

declare(strict_types=1);

/*
 * Night shift S2 (22:00 to 06:00 every day, grace 15, break 60), the night of
 * Tuesday 2026-10-13. A record is dated the night it began. DATA ONLY.
 */

$d = '2026-10-13';

$case = fn (string $id, array|string $rule, string $source, array $punches, array $expect, array $extra = []): array => [
    'id' => $id,
    'rule' => (array) $rule,
    'source' => $source,
    'shift' => 'S2',
    'punches' => $punches,
    'expect' => $expect,
    ...$extra,
];

$in = fn (string $at, string $method = 'web'): array => ['type' => 'in', 'at' => $at, 'method' => $method];
$out = fn (string $at, string $method = 'web'): array => ['type' => 'out', 'at' => $at, 'method' => $method];
$manual = fn (string $in, string $out, string $method = 'manual'): array => ['type' => 'manual', 'in' => $in, 'out' => $out, 'method' => $method];

return [
    $case('GC-NIGHT-001', ['AR-NIGHT-01', 'AR-STATUS-01', 'AR-MIN-04'], '05 SHF-003', [$in('21:55'), $out('+1 06:05')], [
        'days' => [$d => ['live' => 1, 'status' => 'present', 'late' => 0, 'worked' => 430, 'check_in' => '21:55', 'check_out' => '06:05']],
    ]),
    $case('GC-NIGHT-002', ['AR-NIGHT-01', 'AR-SHIFT-05', 'AR-MIN-01'], '05 SHF-004', [$in('+1 00:30')], [
        'days' => [$d => ['live' => 1, 'status' => 'late', 'late' => 150]],
    ]),
    $case('GC-NIGHT-003', ['AR-NIGHT-02'], '05 SHF-013', [$in('21:55'), $out('+1 13:00')], [
        'outcomes' => ['ok', 'refused:no_open_check_in'],
        'days' => [$d => ['live' => 1, 'check_out' => null]],
    ]),
    $case('GC-NIGHT-004', ['AR-NIGHT-02'], '05 SHF-013', [$in('21:55'), $out('+1 11:30')], [
        'outcomes' => ['ok', 'ok'],
        'days' => [$d => ['live' => 1, 'check_out' => '11:30']],
    ]),

    // One night through three channels leaves the same record (05 CAP-001, MAN-002, AIMP-002).
    $case('GC-CHAN-001', ['AR-NIGHT-01', 'AR-MIN-04'], '05 CAP-001', [$in('21:55'), $out('+1 06:05')], [
        'days' => [$d => ['live' => 1, 'status' => 'present', 'worked' => 430, 'check_in' => '21:55', 'check_out' => '06:05']],
    ]),
    $case('GC-CHAN-002', ['AR-NIGHT-01', 'AR-NIGHT-04', 'AR-STATUS-05', 'AR-MIN-04'], '05 CAP-001, MAN-002', [$manual('21:55', '+1 06:05')], [
        'days' => [$d => ['live' => 1, 'status' => 'present', 'worked' => 430, 'check_in' => '21:55', 'check_out' => '06:05']],
    ]),
    $case('GC-CHAN-003', ['AR-NIGHT-01', 'AR-NIGHT-04', 'AR-STATUS-05', 'AR-MIN-04'], '05 CAP-001, AIMP-002', [$manual('21:55', '+1 06:05', 'csv')], [
        'days' => [$d => ['live' => 1, 'status' => 'present', 'worked' => 430, 'check_in' => '21:55', 'check_out' => '06:05']],
    ]),
];

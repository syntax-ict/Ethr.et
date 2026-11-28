<?php

declare(strict_types=1);

use Tests\Accuracy\Oracle\CaseAdapter;
use Tests\Accuracy\Oracle\Undecided;

/*
 * Tests of the oracle only; they assert nothing about the engine.
 *
 * An Undecided rule blanks a field only when its readings would give different
 * answers for that input. Each row below puts one input on the boundary where
 * two readings differ and names the field that must come back Undecided, the
 * rule it must name and the baseline reading it carries; and a control moved
 * one second or one minute off the boundary, where the same field must be
 * answered. Rows are cases in the case-file format, without `expect`.
 */

/** @return array<string, array<string, mixed>> */
function oracleReadingsShifts(): array
{
    return require __DIR__.'/fixtures/shifts.php';
}

/** @return array{type: string, at: string, method: string} */
function oracleReadingsIn(string $at, string $method = 'web'): array
{
    return ['type' => 'in', 'at' => $at, 'method' => $method];
}

/** @return array{type: string, at: string, method: string} */
function oracleReadingsOut(string $at, string $method = 'web'): array
{
    return ['type' => 'out', 'at' => $at, 'method' => $method];
}

/** @return array{type: string, in: string, out: string, method: string} */
function oracleReadingsManual(string $in, string $out, string $method = 'manual'): array
{
    return ['type' => 'manual', 'in' => $in, 'out' => $out, 'method' => $method];
}

/**
 * @param  list<string|int>  $path
 */
function oracleReadingsField(array $answer, array $path): mixed
{
    $node = $answer;
    foreach ($path as $key) {
        $node = $node[$key];
    }

    return $node;
}

/**
 * @return array<string, array{row: array<string, mixed>}>
 */
function oracleReadingsDataset(): array
{
    $d = '2026-10-13';
    $next = '2026-10-14';

    $rows = [
        'AR-CLOCK-06 early departure 17:14:30' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('17:14:30')]],
            'path' => ['days', $d, 'status'], 'rule' => 'AR-CLOCK-06', 'baseline' => 'early_leave',
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('17:14:00')]],
        ],
        'AR-CLOCK-06 break out 10:00:00, in 13:00:30' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('10:00:00'), oracleReadingsIn('13:00:30')]],
            'path' => ['live'], 'rule' => 'AR-CLOCK-06', 'baseline' => 2,
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('10:00:00'), oracleReadingsIn('13:01:00')]],
        ],
        'AR-CLOCK-07 17:45:30 to 22:00:40' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('17:45:30'), oracleReadingsOut('22:00:40')]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-CLOCK-07', 'baseline' => ['normal' => 255],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('17:45:30'), oracleReadingsOut('22:00:00')]],
        ],
        'AR-NIGHT-05 check-out exactly 14 hours after check-in' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('19:00'), oracleReadingsOut('+1 09:00')]],
            'path' => ['outcomes', 1], 'rule' => 'AR-NIGHT-05', 'baseline' => 'ok',
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('19:00'), oracleReadingsOut('+1 08:59')]],
        ],
        'AR-NIGHT-05 check-out exactly 6 hours after shift end' => [
            'case' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:55'), oracleReadingsOut('+1 12:00')]],
            'path' => ['outcomes', 1], 'rule' => 'AR-NIGHT-05', 'baseline' => 'ok',
            'control' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:55'), oracleReadingsOut('+1 11:59')]],
        ],
        'AR-OT-13 S2, holiday on the second day' => [
            'case' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 07:30')], 'setup' => ['holiday' => $next]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-OT-13', 'baseline' => ['normal' => 90],
            // the span ends before the second day's overtime begins
            'control' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 05:00')], 'setup' => ['holiday' => $next]],
        ],
        'AR-OT-13 S2, holiday on the first day' => [
            'case' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 07:30')], 'setup' => ['holiday' => $d]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-OT-13', 'baseline' => ['holiday' => 92, 'holiday_night' => 480],
            // the same night with no holiday anywhere near it
            'control' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 07:30')], 'setup' => ['holiday' => '2026-10-20']],
        ],
        'AR-ABS-08 a record on a day of approved leave' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('19:00')], 'setup' => ['leave' => [$d, $d]]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-ABS-08', 'baseline' => ['normal' => 90],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('19:00')], 'setup' => ['leave' => [$next, $next]]],
        ],
        'AR-MIN-08 worked_day of a second session' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('16:00'), oracleReadingsIn('17:45'), oracleReadingsOut('20:00')]],
            'path' => ['days', $d, 'worked_day'], 'rule' => 'AR-MIN-08', 'baseline' => 470,
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('17:30')]],
        ],
        'AR-DAY-08 manual out 08:33 against an in at 08:30' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsManual('08:00', '08:33')]],
            'path' => ['live'], 'rule' => 'AR-DAY-08', 'baseline' => 1,
            // out 10 minutes from the in: far under either reading
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsManual('08:00', '08:40')]],
        ],
        'AR-OT-14 a chain of overlapping records' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsManual('08:00', '18:00'), oracleReadingsManual('17:50', '21:00', 'csv'), oracleReadingsManual('20:50', '23:30')]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-OT-14', 'baseline' => ['normal' => 30],
            // two overlapping records that every reading of "longest" orders the same way
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsManual('08:00', '18:00'), oracleReadingsManual('17:50', '17:55', 'csv')]],
        ],
        'AR-NIGHT-05 an Automatic punch next morning with no shift' => [
            'case' => ['shift' => null, 'punches' => [oracleReadingsIn('19:00'), ['type' => 'auto', 'at' => '+1 08:00', 'method' => 'kiosk']]],
            'path' => ['days', $d, 'check_out'], 'rule' => 'AR-NIGHT-05', 'baseline' => null,
            // 24 hours later: no reading closes the day
            'control' => ['shift' => null, 'punches' => [oracleReadingsIn('19:00'), ['type' => 'auto', 'at' => '+1 19:30', 'method' => 'kiosk']]],
        ],
    ];

    return array_map(static fn (array $row): array => ['row' => $row], $rows);
}

it('blanks a field on the boundary and answers it one step away', function (array $row): void {
    $shifts = oracleReadingsShifts();

    $blank = oracleReadingsField(CaseAdapter::run($row['case'], $shifts), $row['path']);
    expect($blank)->toBeInstanceOf(Undecided::class);
    expect($blank->rules)->toBe([$row['rule']]);
    expect($blank->literal)->toEqual($row['baseline']);

    $control = oracleReadingsField(CaseAdapter::run($row['control'], $shifts), $row['path']);
    expect($control)->not->toBeInstanceOf(Undecided::class);
})->with(oracleReadingsDataset());

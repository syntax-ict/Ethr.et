<?php

declare(strict_types=1);

use Tests\Accuracy\Oracle\CaseAdapter;
use Tests\Accuracy\Oracle\Undecided;
use Tests\Accuracy\OracleComparer;

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
            'path' => ['days', $d, 'status'], 'rule' => 'AR-CLOCK-06', 'baseline' => 'early_leave', 'values' => ['early_leave', 'present'],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('17:14:00')]],
        ],
        'AR-CLOCK-06 break out 10:00:00, in 13:00:30' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('10:00:00'), oracleReadingsIn('13:00:30')]],
            'path' => ['live'], 'rule' => 'AR-CLOCK-06', 'baseline' => 2, 'values' => [2, 1],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('10:00:00'), oracleReadingsIn('13:01:00')]],
        ],
        'AR-CLOCK-07 17:45:30 to 22:00:40' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('17:45:30'), oracleReadingsOut('22:00:40')]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-CLOCK-07', 'baseline' => ['normal' => 255], 'values' => [['normal' => 255], ['normal' => 254]],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('17:45:30'), oracleReadingsOut('22:00:00')]],
        ],
        'AR-NIGHT-05 check-out exactly 14 hours after check-in' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('19:00'), oracleReadingsOut('+1 09:00')]],
            'path' => ['outcomes', 1], 'rule' => 'AR-NIGHT-05', 'baseline' => 'ok', 'values' => ['ok', 'refused:no_open_check_in'],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('19:00'), oracleReadingsOut('+1 08:59')]],
        ],
        'AR-NIGHT-05 check-out exactly 6 hours after shift end' => [
            'case' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:55'), oracleReadingsOut('+1 12:00')]],
            'path' => ['outcomes', 1], 'rule' => 'AR-NIGHT-05', 'baseline' => 'ok', 'values' => ['ok', 'refused:no_open_check_in'],
            'control' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:55'), oracleReadingsOut('+1 11:59')]],
        ],
        'AR-OT-13 S2, holiday on the second day' => [
            'case' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 07:30')], 'setup' => ['holiday' => $next]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-OT-13', 'baseline' => ['normal' => 90], 'values' => [['normal' => 90], ['holiday' => 90]],
            // the span ends before the second day's overtime begins
            'control' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 05:00')], 'setup' => ['holiday' => $next]],
        ],
        'AR-OT-13 S2, holiday on the first day' => [
            'case' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 07:30')], 'setup' => ['holiday' => $d]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-OT-13', 'baseline' => ['holiday' => 92, 'holiday_night' => 480], 'values' => [['holiday' => 92, 'holiday_night' => 480], ['holiday' => 2, 'holiday_night' => 120, 'normal' => 90, 'night' => 360]],
            // the same night with no holiday anywhere near it
            'control' => ['shift' => 'S2', 'punches' => [oracleReadingsIn('21:58'), oracleReadingsOut('+1 07:30')], 'setup' => ['holiday' => '2026-10-20']],
        ],
        'AR-ABS-08 a record on a day of approved leave' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('19:00')], 'setup' => ['leave' => [$d, $d]]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-ABS-08', 'baseline' => ['normal' => 90], 'values' => [['normal' => 90], ['rest_day' => 630]],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('19:00')], 'setup' => ['leave' => [$next, $next]]],
        ],
        'AR-MIN-08 worked_day of a second session' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('16:00'), oracleReadingsIn('17:45'), oracleReadingsOut('20:00')]],
            'path' => ['days', $d, 'worked_day'], 'rule' => 'AR-MIN-08', 'baseline' => 470, 'values' => [470, 530],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25'), oracleReadingsOut('17:30')]],
        ],
        'AR-DAY-08 manual out 08:33 against an in at 08:30' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsManual('08:00', '08:33')]],
            'path' => ['live'], 'rule' => 'AR-DAY-08', 'baseline' => 1, 'values' => [1, 2],
            // out 10 minutes from the in: far under either reading
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsManual('08:00', '08:40')]],
        ],
        'AR-OT-14 a chain of overlapping records' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsManual('08:00', '18:00'), oracleReadingsManual('17:50', '21:00', 'csv'), oracleReadingsManual('20:50', '23:30')]],
            'path' => ['days', $d, 'overtime'], 'rule' => 'AR-OT-14', 'baseline' => ['normal' => 30], 'values' => [['normal' => 30], ['normal' => 100, 'night' => 90], ['normal' => 190]],
            // two overlapping records that every reading of "longest" orders the same way
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsManual('08:00', '18:00'), oracleReadingsManual('17:50', '17:55', 'csv')]],
        ],
        'AR-NIGHT-05 an Automatic punch next morning with no shift' => [
            'case' => ['shift' => null, 'punches' => [oracleReadingsIn('19:00'), ['type' => 'auto', 'at' => '+1 08:00', 'method' => 'kiosk']]],
            'path' => ['days', $d, 'check_out'], 'rule' => 'AR-NIGHT-05', 'baseline' => null, 'values' => [null, '2026-10-14 08:00:00'],
            // 24 hours later: no reading closes the day
            'control' => ['shift' => null, 'punches' => [oracleReadingsIn('19:00'), ['type' => 'auto', 'at' => '+1 19:30', 'method' => 'kiosk']]],
        ],
        'AR-STATUS-07 late minutes of a check-in at 08:45:11' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:45:11'), oracleReadingsOut('17:34')]],
            'path' => ['days', $d, 'late'], 'rule' => 'AR-STATUS-07', 'baseline' => 15, 'values' => [15, 0],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:46:00'), oracleReadingsOut('17:34')]],
        ],
        'AR-CLOCK-06 early minutes of a check-out at 17:14:30' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('17:14:30')]],
            'path' => ['days', $d, 'early'], 'rule' => 'AR-CLOCK-06', 'baseline' => 15, 'values' => [15, 0],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('17:14:00')]],
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
    expect($blank->values)->toEqual($row['values']);
    expect($blank->values[0])->toEqual($row['baseline']);

    $control = oracleReadingsField(CaseAdapter::run($row['control'], $shifts), $row['path']);
    expect($control)->not->toBeInstanceOf(Undecided::class);
})->with(oracleReadingsDataset());

/**
 * What the person is told for a repeat (same method, under a minute) or a
 * merged second check-in is not stated (AR-DAY-03): no opinion, while the
 * record is still answered.
 *
 * @return array<string, array{row: array<string, mixed>}>
 */
function oracleReadingsOutcomeDataset(): array
{
    $rows = [
        'AR-DAY-03 same method, 30 seconds apart' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25:00'), oracleReadingsIn('08:25:30')]],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25:00'), oracleReadingsIn('08:26:01')]],
        ],
        'AR-DAY-03 other method, 3 minutes apart' => [
            'case' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25', 'mobile'), oracleReadingsIn('08:28', 'kiosk')]],
            'control' => ['shift' => 'S1', 'punches' => [oracleReadingsIn('08:25', 'mobile'), oracleReadingsIn('08:31', 'kiosk')]],
        ],
    ];

    return array_map(static fn (array $row): array => ['row' => $row], $rows);
}

it('gives no opinion on what a repeat is answered with, and still answers the record', function (array $row): void {
    $shifts = oracleReadingsShifts();

    $answer = CaseAdapter::run($row['case'], $shifts);
    expect($answer['outcomes'][0])->toBe('ok');
    expect($answer['outcomes'][1])->toBeNull();
    expect($answer['live'])->toBe(1);
    expect($answer['days']['2026-10-13']['check_in'])->toBe('2026-10-13 08:25:00');

    // one second past the window, "already checked in" is stated (AR-DAY-02)
    $control = CaseAdapter::run($row['control'], $shifts);
    expect($control['outcomes'][1])->toBe('duplicate');
})->with(oracleReadingsOutcomeDataset());

it('refuses a punch dated after the termination date and counts one on or before it (AR-DAY-10)', function (): void {
    $shifts = oracleReadingsShifts();
    $d = '2026-10-13';
    $punches = [oracleReadingsIn('08:30'), oracleReadingsOut('17:30'), oracleReadingsIn('+1 08:30')];

    $left = CaseAdapter::run(['shift' => 'S1', 'punches' => $punches, 'setup' => ['termination_date' => $d]], $shifts, [$d, '2026-10-14']);
    expect($left['outcomes'])->toBe(['ok', 'ok', 'refused:employee_has_left']);
    expect($left['live'])->toBe(1);
    expect($left['days']['2026-10-14']['class'])->toBe('not_employed');
    expect($left['days']['2026-10-14']['live'])->toBe(0);
    expect($left['days'][$d]['check_out'])->toBe('2026-10-13 17:30:00');

    // the day after is the person's last day: the same punch counts
    $stays = CaseAdapter::run(['shift' => 'S1', 'punches' => $punches, 'setup' => ['termination_date' => '2026-10-14']], $shifts, [$d, '2026-10-14']);
    expect($stays['outcomes'])->toBe(['ok', 'ok', 'ok']);
    expect($stays['live'])->toBe(2);
    expect($stays['days']['2026-10-14']['class'])->toBe('workday');
});

it('runs the automatic check-out job in arrival order with the punches (AR-AUTO-01, AR-AUTO-03)', function (): void {
    $shifts = oracleReadingsShifts();
    $d = '2026-10-13';
    $on = ['auto_checkout' => true];

    // a forgotten check-out is closed at the shift's end by the evening run; a later run finds nothing
    $answer = CaseAdapter::run(['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30')], 'setup' => $on, 'auto_runs' => ['19:40', '19:50']], $shifts);
    expect($answer['days'][$d]['check_out'])->toBe('2026-10-13 17:30:00');
    expect($answer['days'][$d]['metadata'])->toBe(['auto_checkout' => true]);
    expect($answer['days'][$d]['worked'])->toBe(480);

    // a run before shift end plus the setting closes nothing; the next one does
    $early = CaseAdapter::run(['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30')], 'setup' => $on, 'auto_runs' => ['19:20']], $shifts);
    expect($early['days'][$d]['check_out'])->toBeNull();
    $both = CaseAdapter::run(['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30')], 'setup' => $on, 'auto_runs' => ['19:20', '19:40']], $shifts);
    expect($both['days'][$d]['check_out'])->toBe('2026-10-13 17:30:00');

    // runs between punches: each day's forgotten check-out is closed by that evening's run
    $two = CaseAdapter::run(['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsIn('+1 08:30')], 'setup' => $on, 'auto_runs' => ['19:40', '+1 19:40']], $shifts);
    expect($two['live'])->toBe(2);
    expect($two['days'][$d]['check_out'])->toBe('2026-10-13 17:30:00');
    expect($two['days']['2026-10-14']['check_out'])->toBe('2026-10-14 17:30:00');

    // with only the second evening's run, the first day is closed by that run too (within two days)
    $late = CaseAdapter::run(['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsIn('+1 08:30')], 'setup' => $on, 'auto_runs' => ['+1 19:40']], $shifts);
    expect($late['days']['2026-10-14']['check_out'])->toBe('2026-10-14 17:30:00');

    // the person's own later check-out replaces an automatic one
    $own = CaseAdapter::run(['shift' => 'S1', 'punches' => [oracleReadingsIn('08:30'), oracleReadingsOut('20:00')], 'setup' => $on, 'auto_runs' => ['19:40']], $shifts);
    expect($own['outcomes'])->toBe(['ok', 'ok']);
    expect($own['days'][$d]['check_out'])->toBe('2026-10-13 20:00:00');
    expect($own['days'][$d]['metadata'])->toBe(['auto_checkout' => false]);

    expect(fn () => CaseAdapter::run(['shift' => 'S1', 'punches' => [], 'setup' => $on, 'auto_runs' => ['19:40'], 'auto_run_at' => '19:40'], $shifts))
        ->toThrow(InvalidArgumentException::class, 'not both');
});

it('answers check-in and check-out with their date, and compares a hand value of either form', function (): void {
    $shifts = oracleReadingsShifts();
    $answer = CaseAdapter::run(['shift' => 'S2', 'punches' => [oracleReadingsIn('21:55'), oracleReadingsOut('+1 06:05')]], $shifts);
    expect($answer['days']['2026-10-13']['check_in'])->toBe('2026-10-13 21:55:00');
    expect($answer['days']['2026-10-13']['check_out'])->toBe('2026-10-14 06:05:00');

    $compare = static fn (array $hand): array => OracleComparer::compare(['expect' => ['days' => ['2026-10-13' => $hand]]], $answer);
    expect($compare(['check_in' => '21:55', 'check_out' => '06:05'])['mismatches'])->toBe([]);
    expect($compare(['check_out' => '06:05:00'])['mismatches'])->toBe([]);
    expect($compare(['check_out' => '2026-10-14 06:05'])['mismatches'])->toBe([]);
    expect($compare(['check_out' => '2026-10-14 06:05:00'])['mismatches'])->toBe([]);
    expect($compare(['check_out' => '2026-10-13 06:05'])['mismatches'])->not->toBe([]);
    expect($compare(['check_out' => '06:06'])['mismatches'])->not->toBe([]);
});

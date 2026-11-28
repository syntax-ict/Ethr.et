<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Tests\Accuracy\Generator\MonthGenerator;

require_once __DIR__.'/Support/engine.php';
require_once __DIR__.'/Support/report.php';

/*
 * Metamorphic properties, over generated months, with the engine alone (no
 * oracle). Each says that a change which should not matter does not change the
 * answer:
 *
 *   p1  every punch sent a second time with its own key, at once, and again
 *       with every punch resent after all others at the end of the month
 *   p2  the organisation's zone changed, every wall-clock time kept (a month
 *       in a zone with daylight saving is not moved: an hour that exists in one
 *       zone and not in another is a real difference)
 *   p3  every live punch reaching the server 30 minutes after it was made
 *       (skipped in a month with automatic check-out; read at the same moment)
 *   p4  every live punch's method changed to another live method. With default
 *       settings only whether two punches share a method matters, so a
 *       bijective rotation (web, mobile, kiosk, qr, biometric, back to web)
 *       tests that the engine does not treat methods differently beyond their
 *       equality. It does not test a rule that prefers one method.
 *   p5  invariants of one run: per work date, when no two live records
 *       overlap, the sum of their spans does not exceed the time from the first
 *       check-in to the last check-out (no double-counted time); no two live
 *       records of a work date overlap unless a conflict names them; no overtime
 *       bucket below zero; late and early are 0 on every record of a day that
 *       is not a workday (engine record-level values)
 *
 * ACCURACY_PROPERTY_MONTHS (default 15) months from ACCURACY_SEED, month i on
 * seed base + i, the same seeds as the differential run. A violation fails the
 * month unless '<seed>/<property>/<date>' is listed in property-findings.php;
 * a listed one that no longer fails fails until removed. Each is written to
 * storage/framework/testing/accuracy/property-<seed>-<property>-<date>.php.
 *
 * Every global function here is prefixed `property`.
 */

const PROPERTY_DEFAULT_MONTHS = 15;

/** The classes of a day on which late and early minutes are 0 by the register. */
const PROPERTY_DAYS_OFF = ['rest_day', 'holiday', 'not_employed'];

const PROPERTY_NAMES = [
    'p1' => 'replaying every punch changes nothing',
    'p2' => 'the same wall-clock month in another zone gives the same answer',
    'p3' => 'a punch arriving 30 minutes late changes nothing',
    'p4' => 'changing every live method to another live one changes nothing',
    'p5' => 'invariants of a run',
];

function propertyMonths(): int
{
    return max(1, (int) (getenv('ACCURACY_PROPERTY_MONTHS') ?: PROPERTY_DEFAULT_MONTHS));
}

function propertyBaseSeed(): int
{
    return (int) (getenv('ACCURACY_SEED') ?: 20261128);
}

/** @return array<string, array{0: int}> */
function propertyDataset(): array
{
    $seeds = [];
    for ($i = 0; $i < propertyMonths(); $i++) {
        $seeds['seed '.(propertyBaseSeed() + $i)] = [propertyBaseSeed() + $i];
    }

    return $seeds;
}

/** @return array<string, string> */
function propertyFindings(): array
{
    return require __DIR__.'/property-findings.php';
}

/**
 * @param  array<string, mixed>|null  $add  ['seed', 'ran' => list of property names, 'violations' => list]
 * @return array<string, mixed>
 */
function propertyTotals(?array $add = null): array
{
    static $totals = ['months' => 0, 'ran' => [], 'violations' => [], 'known' => []];

    if ($add !== null) {
        $totals['months']++;
        foreach ($add['ran'] as $name) {
            $totals['ran'][$name] = ($totals['ran'][$name] ?? 0) + 1;
        }
        foreach ($add['violations'] as $v) {
            $totals['violations'][] = $v;
        }
        foreach ($add['known'] as $name => $k) {
            $t = &$totals['known'][$name];
            $t ??= ['count' => 0, 'seeds' => [], 'example' => null];
            $t['count'] += $k['count'];
            $t['seeds'][$add['seed']] = true;
            $t['example'] ??= $k['example'];
            unset($t);
        }
    }

    return $totals;
}

/**
 * One run of a month on a fresh organisation.
 *
 * @param  array<string, mixed>  $case
 * @param  list<string>  $dates
 * @param  array{replay?: bool, replayAtEnd?: bool, delay?: int, rotate?: bool, readAt?: Carbon}  $options
 * @return array{answer: array<string, mixed>, ctx: array<string, mixed>, readAt: Carbon}
 */
function propertyRun(array $case, string $prefix, array $dates, array $options = []): array
{
    $ctx = accuracyFixture($case);
    $outcomes = accuracySend($case, $ctx, $prefix, $options);

    $readAt = $options['readAt'] ?? (isset($case['now']) ? accuracyMoment($ctx['day'], $ctx['zone'], $case['now']) : Carbon::now());

    return [
        'answer' => accuracyEngineAnswer($case, $ctx, $outcomes, $dates, $options['readAt'] ?? null),
        'ctx' => $ctx,
        'readAt' => $readAt,
    ];
}

/**
 * The fields two runs must share, flat: 'date/field' => value.
 *
 * @param  array<string, mixed>  $answer
 * @return array<string, mixed>
 */
function propertyState(array $answer, bool $withOutcomes): array
{
    $state = ['month/live' => $answer['live'], 'month/conflicts' => $answer['conflicts']];
    if ($withOutcomes) {
        foreach ($answer['outcomes'] as $i => $outcome) {
            $state['punch '.($i + 1).'/outcome'] = $outcome;
        }
    }
    foreach ($answer['days'] as $date => $day) {
        foreach (['live', 'class', 'code', 'absent', 'overtime', 'worked_day', 'late_day', 'early_day'] as $field) {
            $state["{$date}/{$field}"] = $day[$field];
        }
        foreach ($day['records'] as $i => $r) {
            foreach (['status', 'late', 'early', 'worked', 'check_in', 'check_out', 'breaks'] as $field) {
                $state["{$date}/{$field}#".($i + 1)] = $r[$field];
            }
            $state["{$date}/auto_checkout#".($i + 1)] = (bool) ($r['metadata']['auto_checkout'] ?? false);
        }
    }

    return $state;
}

/**
 * Where two states differ: date => lines.
 *
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 * @return array<string, list<string>>
 */
function propertyDifferences(array $a, array $b): array
{
    $found = [];
    foreach (array_keys($a + $b) as $key) {
        $left = $a[$key] ?? '(absent)';
        $right = $b[$key] ?? '(absent)';
        if ($left !== $right) {
            $date = strstr($key, '/', true);
            $found[$date][] = sprintf('%s: baseline %s, changed run %s', $key, json_encode($left), json_encode($right));
        }
    }

    return $found;
}

/**
 * The invariants of one run, from the stored records and the answer.
 *
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $answer
 * @return array{found: array<string, list<string>>, dayOff: list<array{date: string, class: string, line: string}>}
 */
function propertyInvariants(array $ctx, array $answer): array
{
    $found = [];
    $dayOff = [];

    $records = AttendanceRecord::query()
        ->where('employee_id', $ctx['employee']->id)
        ->with('shift')
        ->where('status', '!=', AttendanceStatus::VOIDED)
        ->orderBy('check_in')->orderBy('id')
        ->get();
    $pairs = [];
    foreach (AttendanceConflict::query()->where('employee_id', $ctx['employee']->id)->get(['record_a_id', 'record_b_id']) as $c) {
        $pairs[min($c->record_a_id, $c->record_b_id).'-'.max($c->record_a_id, $c->record_b_id)] = true;
    }

    $closed = $records->filter(fn (AttendanceRecord $r): bool => $r->check_in !== null && $r->check_out !== null)->groupBy(fn (AttendanceRecord $r): string => $r->date->format('Y-m-d'));
    foreach ($closed as $date => $onDay) {
        $overlapping = false;
        foreach ($onDay->values() as $i => $a) {
            foreach ($onDay->values() as $j => $b) {
                if ($j <= $i || ! ($a->check_in < $b->check_out && $b->check_in < $a->check_out)) {
                    continue;
                }
                $overlapping = true;
                if (! isset($pairs[min($a->id, $b->id).'-'.max($a->id, $b->id)])) {
                    $found[$date][] = "records {$a->id} and {$b->id} overlap and no conflict names them";
                }
            }
        }

        if (! $overlapping && $onDay->count() > 1) {
            $spans = $onDay->sum(fn (AttendanceRecord $r): int => $r->check_out->timestamp - $r->check_in->timestamp);
            $hull = $onDay->max(fn (AttendanceRecord $r): int => $r->check_out->timestamp) - $onDay->min(fn (AttendanceRecord $r): int => $r->check_in->timestamp);
            if ($spans > $hull) {
                $found[$date][] = "the spans of {$onDay->count()} records that do not overlap add up to {$spans} s, more than the {$hull} s from the first check-in to the last check-out";
            }
        }
    }

    foreach ($answer['days'] as $date => $day) {
        foreach ($day['overtime'] as $bucket => $minutes) {
            if ($minutes < 0) {
                $found[$date][] = "overtime bucket {$bucket} is {$minutes}";
            }
        }
        // A day of approved leave is not a day off here: AR-ABS-08 leaves its rating undecided.
        if (in_array($day['class'], PROPERTY_DAYS_OFF, true)) {
            foreach ($day['records'] as $i => $r) {
                if ($r['late'] !== 0 || $r['early'] !== 0) {
                    $dayOff[] = ['date' => $date, 'class' => $day['class'], 'line' => sprintf('record #%d on a %s has late %d and early %d minutes (record level)', $i + 1, $day['class'], $r['late'], $r['early'])];
                }
            }
        }
    }

    return ['found' => $found, 'dayOff' => $dayOff];
}

beforeAll(function (): void {
    accuracyClearReport('property-*');
    accuracyClearReport('properties.*');
    fwrite(STDERR, sprintf("\nACCURACY_SEED=%d ACCURACY_PROPERTY_MONTHS=%d\n", propertyBaseSeed(), propertyMonths()));
});

it('keeps its properties on a generated month', function (int $seed): void {
    $case = MonthGenerator::case($seed);
    $first = Carbon::parse($case['day']);
    $dates = [];
    for ($d = 0; $d < $first->daysInMonth; $d++) {
        $dates[] = $first->copy()->addDays($d)->format('Y-m-d');
    }

    // property => date => lines
    $violations = [];
    $ran = ['p5'];
    $merge = function (string $property, array $byDate, string $label) use (&$violations): void {
        foreach ($byDate as $date => $lines) {
            $violations[$property][$date] = [...($violations[$property][$date] ?? []), ...array_map(fn (string $l): string => "{$label}{$l}", $lines)];
        }
    };

    $base = propertyRun($case, "prop-{$seed}-base", $dates);
    $baseState = propertyState($base['answer'], true);
    $baseNoOutcomes = propertyState($base['answer'], false);

    $invariants = propertyInvariants($base['ctx'], $base['answer']);
    $merge('p5', $invariants['found'], '');

    // The day-off rule: a violation of a pattern in property-findings.php is a known finding.
    $patterns = propertyFindings();
    $known = [];
    $dayOffViolations = [];
    foreach ($invariants['dayOff'] as $v) {
        $name = null;
        foreach ($patterns as $candidate => $pattern) {
            if (str_starts_with((string) $candidate, 'pattern:') && in_array('p5', $pattern['properties'], true) && in_array($v['class'], $pattern['classes'], true)) {
                $name = $candidate;

                break;
            }
        }
        if ($name === null) {
            $dayOffViolations[$v['date']][] = $v['line'];

            continue;
        }
        $known[$name]['count'] = ($known[$name]['count'] ?? 0) + 1;
        $known[$name]['example'] ??= ['key' => "{$seed}/p5/{$v['date']}", 'line' => $v['line']];
    }
    $merge('p5', $dayOffViolations, '');

    $ran[] = 'p1';
    $again = propertyRun($case, "prop-{$seed}-p1", $dates, ['replay' => true]);
    $merge('p1', propertyDifferences($baseNoOutcomes, propertyState($again['answer'], false)), 'each punch sent twice at once: ');
    $late = propertyRun($case, "prop-{$seed}-p1e", $dates, ['replayAtEnd' => true, 'readAt' => $base['readAt']]);
    $merge('p1', propertyDifferences($baseNoOutcomes, propertyState($late['answer'], false)), 'every punch resent at the end: ');

    // A zone with daylight saving has hours the others lack: not a like for like move.
    if ($case['zone'] !== 'Europe/Berlin') {
        $ran[] = 'p2';
        foreach (array_diff(['Africa/Addis_Ababa', 'Asia/Dubai', 'America/Bogota'], [$case['zone']]) as $zone) {
            $moved = propertyRun([...$case, 'zone' => $zone], "prop-{$seed}-p2-".substr($zone, -3), $dates);
            $merge('p2', propertyDifferences($baseState, propertyState($moved['answer'], true)), "zone {$zone}: ");
        }
    }

    if (! isset($case['setup']['auto_checkout'])) {
        $ran[] = 'p3';
        $delayed = propertyRun($case, "prop-{$seed}-p3", $dates, ['delay' => 1800, 'readAt' => $base['readAt']]);
        $merge('p3', propertyDifferences($baseNoOutcomes, propertyState($delayed['answer'], false)), '');
    }

    $ran[] = 'p4';
    $rotated = propertyRun($case, "prop-{$seed}-p4", $dates, ['rotate' => true]);
    $merge('p4', propertyDifferences($baseNoOutcomes, propertyState($rotated['answer'], false)), '');
    Carbon::setTestNow();

    $listed = propertyFindings();
    $seen = [];
    $unlisted = [];
    $flat = [];
    foreach ($violations as $property => $byDate) {
        foreach ($byDate as $date => $lines) {
            $key = "{$seed}/{$property}/{$date}";
            $seen[$key] = true;
            $flat[] = ['seed' => $seed, 'property' => $property, 'date' => $date, 'lines' => $lines];
            accuracyWriteCaseFile("property-{$seed}-{$property}-{$date}", $case, ["seed {$seed}, {$property} ".PROPERTY_NAMES[$property].", {$date}", ...$lines]);
            if (! isset($listed[$key])) {
                $unlisted[] = $key.': '.implode(' | ', $lines);
            }
        }
    }
    propertyTotals(['seed' => $seed, 'ran' => $ran, 'violations' => $flat, 'known' => $known]);

    $stale = [];
    foreach ($listed as $key => $why) {
        if (str_starts_with((string) $key, $seed.'/') && ! isset($seen[$key])) {
            $stale[] = "{$key} is listed in property-findings.php but no longer fails: remove it";
        }
    }

    expect([...$unlisted, ...$stale])->toBe([], "seed {$seed}: replay with ACCURACY_SEED={$seed} ACCURACY_PROPERTY_MONTHS=1");
})->with(propertyDataset());

it('lists no pattern that matched nothing', function (): void {
    $totals = propertyTotals();
    if ($totals['months'] < 10) {
        $this->markTestSkipped('too few months to say a pattern matched nothing');
    }
    $stale = [];
    foreach (propertyFindings() as $name => $pattern) {
        if (str_starts_with((string) $name, 'pattern:') && ! isset($totals['known'][$name])) {
            $stale[] = "{$name} is listed in property-findings.php but matched nothing in {$totals['months']} months: remove it";
        }
    }

    expect($stale)->toBe([]);
});

afterAll(function (): void {
    $totals = propertyTotals();
    if ($totals['months'] === 0) {
        return;
    }

    $byProperty = [];
    foreach ($totals['violations'] as $v) {
        $byProperty[$v['property']] = ($byProperty[$v['property']] ?? 0) + 1;
    }
    file_put_contents(accuracyReportDir().'/properties.json', json_encode([
        'base_seed' => propertyBaseSeed(), 'months_requested' => propertyMonths(), 'months_completed' => $totals['months'],
        'known_findings' => array_map(static fn (array $k): array => [...$k, 'seeds' => count($k['seeds'])], $totals['known']),
        'months_run_per_property' => $totals['ran'], 'violating_dates_per_property' => $byProperty, 'violations' => $totals['violations'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $rows = [];
    foreach (PROPERTY_NAMES as $name => $what) {
        $rows[] = sprintf('| %s | %s | %d | %d |', $name, $what, $totals['ran'][$name] ?? 0, $byProperty[$name] ?? 0);
    }
    $knownLines = [];
    foreach ($totals['known'] as $name => $k) {
        $knownLines[] = sprintf(
            '- `%s`: %s. %d violating records in %d months. Example %s: %s.',
            $name, propertyFindings()[$name]['finding'] ?? '', $k['count'], count($k['seeds']), $k['example']['key'], $k['example']['line'],
        );
    }
    file_put_contents(accuracyReportDir().'/properties.md', sprintf(
        "# Properties over generated months\n\nBase seed %d. Months requested %d, completed %d.\n\n| Property | What | Months run | Unexplained violating dates |\n|---|---|---:|---:|\n%s\n\n## Known findings\n\n%s\n",
        propertyBaseSeed(), propertyMonths(), $totals['months'], implode("\n", $rows), $knownLines === [] ? 'None.' : implode("\n", $knownLines),
    ));
});

<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Accuracy\Generator\MonthGenerator;

require_once __DIR__.'/Support/engine.php';
require_once __DIR__.'/Support/report.php';

/*
 * Metamorphic properties, over generated months, with the engine alone (no
 * oracle). Each says that a change which should not matter does not change the
 * answer:
 *
 *   p1  replaying every punch a second time, same idempotency key
 *   p2  the organisation's zone changed, every wall-clock time kept
 *   p3  every live punch reaching the server 30 minutes after it was made
 *       (skipped in a month with automatic check-out; read at the same moment)
 *   p4  every live punch's method changed to another live method (a fixed
 *       rotation, so which punches share a method does not change)
 *   p5  invariants of one run: 0 <= worked <= the record's span; no overtime
 *       bucket below zero; no late or early minutes on a record of a day that
 *       is not a workday; no two live records of a work date overlap unless a
 *       conflict joins them
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

const PROPERTY_ROTATION = ['web' => 'mobile', 'mobile' => 'kiosk', 'kiosk' => 'qr', 'qr' => 'biometric', 'biometric' => 'web'];

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
 * @param  array<string, mixed>|null  $add  ['seed', 'months' => 1, 'violations' => list, 'run' => list of property names]
 * @return array<string, mixed>
 */
function propertyTotals(?array $add = null): array
{
    static $totals = ['months' => 0, 'ran' => [], 'violations' => []];

    if ($add !== null) {
        $totals['months']++;
        foreach ($add['ran'] as $name) {
            $totals['ran'][$name] = ($totals['ran'][$name] ?? 0) + 1;
        }
        foreach ($add['violations'] as $v) {
            $totals['violations'][] = $v;
        }
    }

    return $totals;
}

/**
 * One run of a month on a fresh organisation.
 *
 * @param  array<string, mixed>  $case
 * @param  array{replay?: bool, delay?: int, rotate?: bool, readAt?: Carbon}  $options
 * @return array{answer: array<string, mixed>, ctx: array<string, mixed>, readAt: Carbon}
 */
function propertyRun(array $case, string $prefix, array $dates, array $options = []): array
{
    $ctx = accuracyFixture($case);

    $outcomes = [];
    foreach ($case['punches'] as $i => $punch) {
        if (($options['rotate'] ?? false) && $punch['type'] !== 'manual') {
            $punch['method'] = PROPERTY_ROTATION[$punch['method']];
        }
        // A manual entry is entered when it was made; only live punches are late.
        $delay = $punch['type'] === 'manual' ? 0 : ($options['delay'] ?? 0);
        $outcomes[] = accuracyPunch($ctx, $punch, "{$prefix}-{$i}", $delay);
        if ($options['replay'] ?? false) {
            accuracyPunch($ctx, $punch, "{$prefix}-{$i}", $delay);
        }
    }

    if (isset($case['setup']['auto_checkout'])) {
        Carbon::setTestNow(accuracyMoment($ctx['day'], $ctx['zone'], $case['auto_run_at']));
        expect(Artisan::call('attendance:auto-checkout'))->toBe(0);
    }

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
        foreach (['live', 'class', 'code', 'absent', 'overtime', 'worked_day'] as $field) {
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
 * @return array<string, list<string>> date => lines
 */
function propertyInvariants(array $ctx, array $answer): array
{
    $found = [];

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

    foreach ($records as $r) {
        $date = $r->date->format('Y-m-d');
        $span = $r->check_in !== null && $r->check_out !== null ? intdiv(max(0, $r->check_out->timestamp - $r->check_in->timestamp), 60) : 0;
        $worked = $r->workedMinutes();
        if ($worked < 0 || $worked > $span) {
            $found[$date][] = "record {$r->id} worked {$worked} minutes against a span of {$span}";
        }
    }

    foreach ($records as $i => $a) {
        foreach ($records as $j => $b) {
            if ($j <= $i || $a->date->format('Y-m-d') !== $b->date->format('Y-m-d')) {
                continue;
            }
            if ($a->check_in === null || $a->check_out === null || $b->check_in === null || $b->check_out === null) {
                continue;
            }
            $overlap = $a->check_in < $b->check_out && $b->check_in < $a->check_out;
            if ($overlap && ! isset($pairs[min($a->id, $b->id).'-'.max($a->id, $b->id)])) {
                $found[$a->date->format('Y-m-d')][] = "records {$a->id} and {$b->id} overlap and no conflict joins them";
            }
        }
    }

    foreach ($answer['days'] as $date => $day) {
        foreach ($day['overtime'] as $bucket => $minutes) {
            if ($minutes < 0) {
                $found[$date][] = "overtime bucket {$bucket} is {$minutes}";
            }
        }
        if ($day['class'] !== 'workday') {
            foreach ($day['records'] as $i => $r) {
                if ($r['late'] !== 0 || $r['early'] !== 0) {
                    $found[$date][] = sprintf('record #%d on a %s has late %d and early %d minutes (record level)', $i + 1, $day['class'], $r['late'], $r['early']);
                }
            }
        }
    }

    return $found;
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

    $base = propertyRun($case, "prop-{$seed}-base", $dates);
    $baseState = propertyState($base['answer'], true);
    $baseNoOutcomes = propertyState($base['answer'], false);

    foreach (propertyInvariants($base['ctx'], $base['answer']) as $date => $lines) {
        $violations['p5'][$date] = $lines;
    }

    $ran[] = 'p1';
    $again = propertyRun($case, "prop-{$seed}-p1", $dates, ['replay' => true]);
    foreach (propertyDifferences($baseNoOutcomes, propertyState($again['answer'], false)) as $date => $lines) {
        $violations['p1'][$date] = $lines;
    }

    $ran[] = 'p2';
    foreach (array_diff(['Africa/Addis_Ababa', 'Asia/Dubai', 'America/Bogota'], [$case['zone']]) as $zone) {
        $moved = propertyRun([...$case, 'zone' => $zone], "prop-{$seed}-p2-".substr($zone, -3), $dates);
        foreach (propertyDifferences($baseState, propertyState($moved['answer'], true)) as $date => $lines) {
            $violations['p2'][$date] = [...($violations['p2'][$date] ?? []), ...array_map(fn (string $l): string => "zone {$zone}: {$l}", $lines)];
        }
    }

    if (! isset($case['setup']['auto_checkout'])) {
        $ran[] = 'p3';
        $late = propertyRun($case, "prop-{$seed}-p3", $dates, ['delay' => 1800, 'readAt' => $base['readAt']]);
        foreach (propertyDifferences($baseNoOutcomes, propertyState($late['answer'], false)) as $date => $lines) {
            $violations['p3'][$date] = $lines;
        }
    }

    $ran[] = 'p4';
    $rotated = propertyRun($case, "prop-{$seed}-p4", $dates, ['rotate' => true]);
    foreach (propertyDifferences($baseNoOutcomes, propertyState($rotated['answer'], false)) as $date => $lines) {
        $violations['p4'][$date] = $lines;
    }
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
    propertyTotals(['seed' => $seed, 'ran' => $ran, 'violations' => $flat]);

    $stale = [];
    foreach ($listed as $key => $why) {
        if (str_starts_with($key, $seed.'/') && ! isset($seen[$key])) {
            $stale[] = "{$key} is listed in property-findings.php but no longer fails: remove it";
        }
    }

    expect([...$unlisted, ...$stale])->toBe([], "seed {$seed}: replay with ACCURACY_SEED={$seed} ACCURACY_PROPERTY_MONTHS=1");
})->with(propertyDataset());

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
        'base_seed' => propertyBaseSeed(), 'months' => $totals['months'], 'months_run_per_property' => $totals['ran'],
        'violating_dates_per_property' => $byProperty, 'violations' => $totals['violations'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $rows = [];
    foreach (PROPERTY_NAMES as $name => $what) {
        $rows[] = sprintf('| %s | %s | %d | %d |', $name, $what, $totals['ran'][$name] ?? 0, $byProperty[$name] ?? 0);
    }
    file_put_contents(accuracyReportDir().'/properties.md', sprintf(
        "# Properties over generated months\n\nBase seed %d, %d months.\n\n| Property | What | Months run | Violating dates |\n|---|---|---:|---:|\n%s\n",
        propertyBaseSeed(), $totals['months'], implode("\n", $rows),
    ));
});

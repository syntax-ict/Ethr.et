<?php

declare(strict_types=1);

use App\Enums\TimesheetCode;
use App\Services\Attendance\TimesheetLedger;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;
use Tests\Accuracy\Generator\MonthGenerator;
use Tests\Accuracy\Oracle\CaseAdapter;
use Tests\Accuracy\Oracle\NoOpinion;
use Tests\Accuracy\Oracle\Undecided;

require_once __DIR__.'/Support/engine.php';
require_once __DIR__.'/Support/report.php';

/*
 * The differential test. Each seed makes one employee-month (MonthGenerator),
 * the engine is fed every punch, and the engine's answer is compared field by
 * field with the independent oracle's, for every date of the month. Neither is
 * the truth: a disagreement says one of them, or the register, is wrong, and a
 * person triages it.
 *
 * A field the oracle marks NoOpinion (the register is silent) is not compared.
 * A field it marks Undecided is no free pass: the oracle carries every reading's
 * value, and an engine value that no reading gives is a disagreement; one that
 * a reading gives is counted as undecided, engine inside the readings, by rule.
 *
 * ACCURACY_MONTHS (default 70, about 2,000 employee-days) and ACCURACY_SEED
 * (default below): month i uses seed base + i, so
 *   ACCURACY_SEED=<that seed> ACCURACY_MONTHS=1
 * replays one month alone.
 *
 * A disagreement fails the month unless 'seed/date/field' is listed in
 * generated-findings.php. A listed one that no longer disagrees fails too. The
 * report goes to storage/framework/testing/accuracy: summary.json, summary.md
 * and one <seed>-<date>.php per disagreeing date, holding the generated case
 * with `expect` left as a TODO. Nothing is ever written into `expect` from an
 * answer.
 *
 * Every global function here is prefixed `generated`.
 */

const GENERATED_DEFAULT_SEED = 20261128;

const GENERATED_DEFAULT_MONTHS = 70;

/** The classes of a day on which late and early minutes are 0 by the register. */
const GENERATED_DAYS_OFF = ['rest_day', 'holiday', 'not_employed'];

function generatedSeed(): int
{
    return (int) (getenv('ACCURACY_SEED') ?: GENERATED_DEFAULT_SEED);
}

function generatedMonths(): int
{
    return max(1, (int) (getenv('ACCURACY_MONTHS') ?: GENERATED_DEFAULT_MONTHS));
}

/** @return array<string, array{0: int}> */
function generatedDataset(): array
{
    $seeds = [];
    for ($i = 0; $i < generatedMonths(); $i++) {
        $seeds['seed '.(generatedSeed() + $i)] = [generatedSeed() + $i];
    }

    return $seeds;
}

/** @return array<string, string> */
function generatedFindings(): array
{
    return require __DIR__.'/generated-findings.php';
}

/**
 * What every month adds up to. A static so the figures survive from one test to
 * the next and afterAll can write them even when a month failed.
 *
 * @param  array<string, mixed>|null  $add  one month's result; null reads the total
 * @return array<string, mixed>
 */
function generatedTotals(?array $add = null): array
{
    static $totals = [
        'months' => 0, 'days' => 0, 'punches' => 0, 'fields' => [], 'disagreements' => [], 'ledger' => [], 'seeds' => [],
        'bad_months' => 0, 'unexplained_months' => 0, 'known' => [], 'dropped' => ['undecided' => 0, 'different' => 0],
        'profile' => ['behaviours' => [], 'behaviour_months' => [], 'setup' => [], 'shift' => [], 'zone' => []],
    ];

    if ($add !== null) {
        $totals['months']++;
        $totals['days'] += $add['days'];
        $totals['punches'] += $add['punches'];
        $totals['seeds'][] = $add['seed'];
        $bad = $add['ledger'] !== [];
        $unexplained = $bad;
        foreach ($add['rows'] as $row) {
            $f = &$totals['fields'][$row['field']];
            $f ??= ['agreed' => 0, 'disagreed' => 0, 'inf_agreed' => 0, 'inf_disagreed' => 0, 'inside' => [], 'outside' => [], 'no_opinion' => 0];
            switch ($row['status']) {
                case 'agreed':
                    $f['agreed']++;
                    $f['inf_agreed'] += $row['informative'] ? 1 : 0;

                    break;
                case 'disagreed':
                    $f['disagreed']++;
                    $f['inf_disagreed'] += $row['informative'] ? 1 : 0;
                    if ($row['outside'] !== null) {
                        $f['outside'][$row['outside']] = ($f['outside'][$row['outside']] ?? 0) + 1;
                    }
                    $totals['disagreements'][] = ['seed' => $add['seed'], 'key' => generatedKey($add['seed'], $row)] + $row;
                    $bad = true;
                    if ($row['known'] !== null) {
                        $k = &$totals['known'][$row['known']];
                        $k ??= ['count' => 0, 'seeds' => [], 'fields' => [], 'classes' => [], 'example' => null];
                        $k['count']++;
                        $k['seeds'][$add['seed']] = true;
                        $k['fields'][$row['field']] = ($k['fields'][$row['field']] ?? 0) + 1;
                        $k['classes'][$row['class']] = ($k['classes'][$row['class']] ?? 0) + 1;
                        $k['example'] ??= ['key' => generatedKey($add['seed'], $row), 'engine' => $row['engine'], 'oracle' => $row['oracle']];
                        unset($k);
                    } else {
                        $unexplained = true;
                    }

                    break;
                case 'undecided':
                    $f['inside'][$row['rule']] = ($f['inside'][$row['rule']] ?? 0) + 1;

                    break;
                case 'no_opinion':
                    $f['no_opinion']++;

                    break;
            }
            unset($f);
        }
        $totals['bad_months'] += $bad ? 1 : 0;
        $totals['unexplained_months'] += $unexplained ? 1 : 0;
        foreach ($add['dropped'] as $reason => $n) {
            $totals['dropped'][$reason] += $n;
        }
        foreach ($add['ledger'] as $problem) {
            $totals['ledger'][] = $problem;
        }

        $profile = $add['profile'];
        foreach ($profile['behaviours'] as $name => $n) {
            $totals['profile']['behaviours'][$name] = ($totals['profile']['behaviours'][$name] ?? 0) + $n;
            $totals['profile']['behaviour_months'][$name] = ($totals['profile']['behaviour_months'][$name] ?? 0) + 1;
        }
        foreach ($profile['setup'] as $name) {
            $totals['profile']['setup'][$name] = ($totals['profile']['setup'][$name] ?? 0) + 1;
        }
        $totals['profile']['shift'][$profile['shift']] = ($totals['profile']['shift'][$profile['shift']] ?? 0) + 1;
        $totals['profile']['zone'][$profile['zone']] = ($totals['profile']['zone'][$profile['zone']] ?? 0) + 1;
    }

    return $totals;
}

/**
 * One leaf: the engine's value against the oracle's. An Undecided oracle value
 * is no free pass: an engine value that no reading gives is a disagreement.
 *
 * @param  (callable(mixed): mixed)|null  $normalise  applied to the engine's value, the oracle's and every reading
 * @return array{status: string, rule: ?string, outside: ?string}
 */
function generatedLeaf(mixed $engine, mixed $oracle, ?callable $normalise = null): array
{
    if ($oracle instanceof NoOpinion) {
        return ['status' => 'no_opinion', 'rule' => null, 'outside' => null];
    }
    $same = $normalise ?? static fn (mixed $v): mixed => $v;
    if ($oracle instanceof Undecided) {
        $inside = in_array($same($engine), array_map($same, $oracle->values), true);

        return $inside
            ? ['status' => 'undecided', 'rule' => $oracle->label(), 'outside' => null]
            : ['status' => 'disagreed', 'rule' => $oracle->label(), 'outside' => $oracle->label()];
    }

    return ['status' => $same($engine) === $same($oracle) ? 'agreed' : 'disagreed', 'rule' => null, 'outside' => null];
}

/**
 * The pattern of generated-findings.php that a disagreement belongs to, if any:
 * its field is listed, the day's class is listed, the oracle's value is 0 and
 * (when the pattern says so) the engine's is not.
 *
 * @param  array<string, mixed>  $row
 * @param  array<string, mixed>  $listed
 */
function generatedKnown(array $row, array $listed): ?string
{
    if ($row['status'] !== 'disagreed') {
        return null;
    }
    $zero = static fn (mixed $v): bool => $v === 0 || $v === [0, 0];
    foreach ($listed as $name => $pattern) {
        if (! str_starts_with((string) $name, 'pattern:')) {
            continue;
        }
        if (! in_array($row['field'], $pattern['fields'], true) || ! in_array($row['class'], $pattern['classes'], true) || ! $zero($row['oracle'])) {
            continue;
        }
        $engine = is_array($row['engine']) ? array_sum($row['engine']) : $row['engine'];
        if (($pattern['engine_nonzero'] ?? false) && $engine === 0) {
            continue;
        }

        return $name;
    }

    return null;
}

/** Overtime buckets as a person would compare them: no zeros, sorted by name. */
function generatedBuckets(mixed $buckets): mixed
{
    if (! is_array($buckets)) {
        return $buckets;
    }
    $buckets = array_filter($buckets, static fn (mixed $minutes): bool => $minutes !== 0);
    ksort($buckets);

    return $buckets;
}

/**
 * @param  array<string, mixed>  $case
 * @return list<string> the dates a punch of the case falls on
 */
function generatedPunchDates(array $case): array
{
    $first = Carbon::parse($case['day']);
    $dates = [];
    foreach ($case['punches'] as $p) {
        $text = $p['type'] === 'manual' ? $p['in'] : $p['at'];
        $offset = preg_match('/^\+(\d+) /', $text, $m) === 1 ? (int) $m[1] : 0;
        $dates[] = $first->copy()->addDays($offset)->format('Y-m-d');
    }

    return array_values(array_unique($dates));
}

/**
 * Every field of every date, engine against oracle. Each row says whether the
 * comparison was informative: one that could have disagreed (for example
 * `absent` only on a workday with no record).
 *
 * @param  array<string, mixed>  $engine  accuracyEngineAnswer()
 * @param  array<string, mixed>  $oracle  CaseAdapter::run()
 * @param  list<string>  $dates
 * @param  list<string>  $punchDates
 * @return array{rows: list<array<string, mixed>>, dropped: array{undecided: int, different: int}}
 */
function generatedCompare(array $engine, array $oracle, array $dates, array $punchDates, bool $autoSetup): array
{
    $rows = [];
    $dropped = ['undecided' => 0, 'different' => 0];
    $class = null;
    $add = function (string $field, mixed $mine, mixed $theirs, ?string $date = null, ?int $record = null, bool $informative = true, ?callable $normalise = null) use (&$rows, &$class): void {
        $rows[] = ['field' => $field, 'date' => $date, 'class' => $class, 'record' => $record, 'engine' => $mine, 'oracle' => $theirs, 'informative' => $informative, 'known' => null, ...generatedLeaf($mine, $theirs, $normalise)];
    };
    $positive = static fn (mixed $v): bool => is_int($v) && $v > 0;

    $add('live (month)', $engine['live'], $oracle['live']);
    $add('conflicts (month)', $engine['conflicts'], $oracle['conflicts'], informative: $engine['conflicts'] > 0 || $positive($oracle['conflicts']));
    foreach ($engine['outcomes'] as $i => $outcome) {
        // A null outcome is the register giving no answer for that punch.
        $theirs = ($oracle['outcomes'][$i] ?? null) ?? new NoOpinion('the register gives no outcome for this punch');
        $add('outcome (per punch)', $outcome, $theirs, null, $i + 1, $outcome !== 'ok' || $theirs !== 'ok');
    }

    foreach ($dates as $date) {
        $e = $engine['days'][$date];
        $o = $oracle['days'][$date];
        $eitherRecord = $e['live'] > 0 || $positive($o['live']);
        $class = $e['class'];

        $add('live', $e['live'], $o['live'], $date, informative: $eitherRecord || in_array($date, $punchDates, true));
        $add('class', $e['class'], $o['class'], $date, informative: $e['class'] !== 'workday' || $o['class'] !== 'workday');
        $add('absent', $e['absent'], $o['absent'], $date, informative: $e['class'] === 'workday' && ($e['live'] === 0 || $o['live'] === 0));
        $add('overtime', $e['overtime'], $o['overtime'], $date, informative: $eitherRecord, normalise: 'generatedBuckets');
        $add('worked_day', $e['worked_day'], $o['worked_day'], $date, informative: $eitherRecord);

        // The ledger's day-level late and early, against the oracle's record-level
        // values: the oracle has no day-level key, so only a workday with one live record.
        if ($e['class'] === 'workday' && $e['live'] === 1 && $o['live'] === 1 && $o['class'] === 'workday') {
            $add('late_day', $e['late_day'], $o['records'][0]['late'], $date);
            $add('early_day', $e['early_day'], $o['records'][0]['early'], $date);
        }

        // The rule of a day off, on the engine's record-level values. A day of
        // approved leave is not one: the register leaves its rating undecided
        // (AR-ABS-08), so a record there counts as undecided, not as a disagreement.
        if (in_array($e['class'], GENERATED_DAYS_OFF, true)) {
            foreach ($e['records'] as $i => $r) {
                $add('late and early on a day off', [$r['late'], $r['early']], [0, 0], $date, $i);
            }
        } elseif ($e['class'] === 'on_leave') {
            foreach ($e['records'] as $i => $r) {
                $add('late and early on a day off', [$r['late'], $r['early']], new Undecided(['AR-ABS-08'], [0, 0], [[0, 0], [$r['late'], $r['early']]]), $date, $i);
            }
        }

        // Per-record fields only where both sides found the same number of records.
        $liveKnown = is_int($o['live']);
        if (! $liveKnown || $o['live'] !== $e['live']) {
            $count = max(count($e['records']), is_array($o['records'] ?? null) ? count($o['records']) : 0) * 8;
            $dropped[$liveKnown ? 'different' : 'undecided'] += $count;

            continue;
        }
        foreach ($e['records'] as $i => $r) {
            $theirs = $o['records'][$i];
            foreach (['status', 'late', 'early', 'worked', 'check_in', 'check_out', 'breaks'] as $field) {
                $informative = match ($field) {
                    'breaks' => $r['breaks'] !== [] || (is_array($theirs['breaks']) && $theirs['breaks'] !== []),
                    'check_out' => $r['check_out'] !== null || $theirs['check_out'] !== null,
                    default => true,
                };
                $add($field, $r[$field], $theirs[$field], $date, $i, $informative);
            }
            $meta = $theirs['metadata'];
            $mine = (bool) ($r['metadata']['auto_checkout'] ?? false);
            $theirAuto = is_array($meta) ? $meta['auto_checkout'] : $meta;
            $add('auto_checkout', $mine, $theirAuto, $date, $i, $autoSetup || $mine || $theirAuto === true);
        }
    }

    return ['rows' => $rows, 'dropped' => $dropped];
}

/**
 * The ledger of the same month: consistent, and saying what payroll reads. The
 * ledger and payroll share code, so this is a consistency check, not an
 * independent one. `attendanceFigures()` carries overtime only, so absences are
 * compared with `unexplainedAbsencesFor()`, as TimesheetLedgerTest does.
 *
 * @param  array<string, mixed>  $ctx
 * @param  list<string>  $dates
 * @return list<string>
 */
function generatedLedgerProblems(array $ctx, array $dates): array
{
    $employee = $ctx['employee']->fresh();
    $tenant = $ctx['tenant'];
    $from = Carbon::parse($dates[0]);
    $to = Carbon::parse($dates[count($dates) - 1]);
    $rules = app(StatutoryRules::class)->for($tenant, $from);

    $row = app(TimesheetLedger::class)->build(collect([$employee]), $from, $to, $rules)['employees'][$employee->id];
    $problems = [];

    if ($row['check_ok'] !== true) {
        $problems[] = 'ledger/check_ok: the ledger is not consistent()';
    }

    $payroll = app(PayrollEngine::class);
    $figures = $payroll->attendanceFigures($employee, $from, $to, $rules, false)['overtime'];
    if (generatedBuckets($row['totals']['overtime']) !== generatedBuckets($figures)) {
        $problems[] = 'ledger/overtime: ledger said '.json_encode(generatedBuckets($row['totals']['overtime'])).', payroll said '.json_encode(generatedBuckets($figures));
    }

    $absentInLedger = array_keys(array_filter($row['cells'], static fn (array $cell): bool => $cell['code'] === TimesheetCode::ABSENT));
    $absentInPayroll = $payroll->unexplainedAbsencesFor(collect([$employee]), $from, $to)[$employee->id] ?? [];
    sort($absentInLedger);
    sort($absentInPayroll);
    if ($absentInLedger !== $absentInPayroll) {
        $problems[] = 'ledger/absences: ledger said '.json_encode($absentInLedger).', payroll said '.json_encode($absentInPayroll);
    }

    return $problems;
}

/** The key a disagreement is listed under in generated-findings.php. */
function generatedKey(int $seed, array $row): string
{
    $suffix = $row['record'] === null ? '' : '#'.($row['record'] + ($row['date'] === null ? 0 : 1));

    return $seed.'/'.($row['date'] ?? 'month').'/'.$row['field'].$suffix;
}

/** What a side said, with every reading when it was Undecided. */
function generatedShow(mixed $value): string
{
    if ($value instanceof Undecided) {
        $value = ['readings of '.$value->label() => $value->values];
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: var_export($value, true);
}

beforeAll(function (): void {
    accuracyClearReport('summary.*');
    accuracyClearReport('[0-9]*-*.php');
    fwrite(STDERR, sprintf("\nACCURACY_SEED=%d ACCURACY_MONTHS=%d (month i uses seed base + i)\n", generatedSeed(), generatedMonths()));
});

it('agrees with the independent oracle on a generated month', function (int $seed): void {
    $case = MonthGenerator::case($seed);
    $ctx = accuracyFixture($case);
    $outcomes = accuracySend($case, $ctx, "gen-{$seed}");

    $first = Carbon::parse($case['day']);
    $dates = [];
    for ($d = 0; $d < $first->daysInMonth; $d++) {
        $dates[] = $first->copy()->addDays($d)->format('Y-m-d');
    }

    $engine = accuracyEngineAnswer($case, $ctx, $outcomes, $dates);
    $oracle = CaseAdapter::run($case, require __DIR__.'/fixtures/shifts.php', $dates);

    $listed = generatedFindings();
    $compared = generatedCompare($engine, $oracle, $dates, generatedPunchDates($case), isset($case['setup']['auto_checkout']));
    $rows = array_map(static fn (array $row): array => ['known' => generatedKnown($row, $listed)] + $row, $compared['rows']);
    $ledger = generatedLedgerProblems($ctx, $dates);
    Carbon::setTestNow();

    generatedTotals([
        'seed' => $seed, 'days' => count($dates), 'punches' => count($case['punches']), 'rows' => $rows, 'dropped' => $compared['dropped'],
        'profile' => $case['profile'], 'ledger' => array_map(static fn (string $p): string => $seed.'/'.$p, $ledger),
    ]);

    // One file per disagreeing date (or `month`), ready to become a golden case.
    $files = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'disagreed') {
            $files[$row['date'] ?? 'month'][] = sprintf(
                'field %s%s: engine said %s, oracle said %s%s',
                $row['field'],
                $row['record'] !== null ? ' #'.($row['record'] + ($row['date'] === null ? 0 : 1)) : '',
                generatedShow($row['engine']),
                generatedShow($row['oracle']),
                $row['outside'] !== null ? ' (outside every reading)' : '',
            );
        }
    }
    foreach ($files as $date => $lines) {
        accuracyWriteCaseFile("{$seed}-{$date}", $case, ["seed {$seed}, {$date}", ...$lines]);
    }
    if ($ledger !== []) {
        accuracyWriteCaseFile("{$seed}-ledger", $case, ["seed {$seed}", ...$ledger]);
    }

    $seen = [];
    $unlisted = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'disagreed') {
            $key = generatedKey($seed, $row);
            $seen[$key] = true;
            if (! isset($listed[$key]) && $row['known'] === null) {
                $unlisted[] = sprintf('%s: engine said %s, oracle said %s', $key, generatedShow($row['engine']), generatedShow($row['oracle']));
            }
        }
    }
    foreach ($ledger as $problem) {
        $key = $seed.'/'.strstr($problem, ':', true);
        $seen[$key] = true;
        if (! isset($listed[$key])) {
            $unlisted[] = "{$seed}/{$problem}";
        }
    }

    // A listed finding of this seed that has stopped disagreeing.
    $stale = [];
    foreach ($listed as $key => $why) {
        if (str_starts_with((string) $key, $seed.'/') && ! isset($seen[$key])) {
            $stale[] = "{$key} is listed in generated-findings.php but no longer disagrees: remove it";
        }
    }

    expect([...$unlisted, ...$stale])->toBe([], "seed {$seed}: replay with ACCURACY_SEED={$seed} ACCURACY_MONTHS=1; cases are in storage/framework/testing/accuracy");
})->with(generatedDataset());

it('lists no pattern that matched nothing', function (): void {
    $totals = generatedTotals();
    if ($totals['months'] < 20) {
        $this->markTestSkipped('too few months to say a pattern matched nothing');
    }
    $stale = [];
    foreach (generatedFindings() as $name => $pattern) {
        if (str_starts_with((string) $name, 'pattern:') && ! isset($totals['known'][$name])) {
            $stale[] = "{$name} is listed in generated-findings.php but matched nothing in {$totals['months']} months: remove it";
        }
    }

    expect($stale)->toBe([]);
});

afterAll(function (): void {
    $totals = generatedTotals();
    if ($totals['months'] === 0) {
        return;
    }

    $pct = static fn (int $k, int $n): string => $n === 0 ? 'n/a' : sprintf('%.2f%%', 100 * $k / $n);
    $wilson = static function (int $k, int $n): string {
        [$low, $high] = accuracyWilson($k, $n);

        return $n === 0 ? 'n/a' : sprintf('%.2f%% to %.2f%%', 100 * $low, 100 * $high);
    };
    $join = static function (array $byRule): string {
        $parts = [];
        foreach ($byRule as $rule => $n) {
            $parts[] = "{$rule} {$n}";
        }

        return $parts === [] ? '0' : implode(', ', $parts);
    };
    $round = static fn (array $pair): array => array_map(static fn (float $x): float => round($x, 4), $pair);

    $fields = [];
    $lines = [];
    foreach ($totals['fields'] as $name => $f) {
        $compared = $f['agreed'] + $f['disagreed'];
        $informative = $f['inf_agreed'] + $f['inf_disagreed'];
        $fields[$name] = [
            'compared' => $compared, 'agreed' => $f['agreed'], 'disagreed' => $f['disagreed'],
            'informative_compared' => $informative, 'informative_agreed' => $f['inf_agreed'],
            'undecided_engine_inside_readings' => $f['inside'],
            'undecided_engine_outside_readings_counted_as_disagreed' => $f['outside'],
            'no_opinion' => $f['no_opinion'],
            'agreement_rate' => $compared === 0 ? null : round($f['agreed'] / $compared, 4),
            'wilson95' => $round(accuracyWilson($f['agreed'], $compared)),
            'informative_agreement_rate' => $informative === 0 ? null : round($f['inf_agreed'] / $informative, 4),
            'informative_wilson95' => $round(accuracyWilson($f['inf_agreed'], $informative)),
        ];
        $lines[] = sprintf(
            '| %s | %d | %d | %d | %s | %s | %d | %s | %s | %s | %d |',
            $name, $compared, $f['agreed'], $f['disagreed'], $pct($f['agreed'], $compared), $wilson($f['agreed'], $compared),
            $informative, $pct($f['inf_agreed'], $informative), $wilson($f['inf_agreed'], $informative),
            $join($f['inside']).' / '.$join($f['outside']), $f['no_opinion'],
        );
    }

    $profile = $totals['profile'];
    arsort($profile['behaviours']);
    arsort($profile['setup']);
    $freq = [];
    foreach ($profile['behaviours'] as $name => $n) {
        $freq[] = sprintf('| %s | %d | %d |', $name, $n, $profile['behaviour_months'][$name]);
    }
    $setupLines = [];
    foreach ($profile['setup'] as $name => $n) {
        $setupLines[] = sprintf('| %s | %d of %d |', $name, $n, $totals['months']);
    }
    foreach (['shift', 'zone'] as $kind) {
        foreach ($profile[$kind] as $name => $n) {
            $setupLines[] = sprintf('| %s %s | %d of %d |', $kind, $name, $n, $totals['months']);
        }
    }

    $report = [
        'base_seed' => generatedSeed(),
        'months_requested' => generatedMonths(),
        'months_completed' => $totals['months'],
        'months_with_a_disagreement' => $totals['bad_months'],
        'months_with_an_unexplained_disagreement' => $totals['unexplained_months'],
        'known_findings' => array_map(static fn (array $k): array => [...$k, 'seeds' => count($k['seeds'])], $totals['known']),
        'months_with_a_disagreement_wilson95' => $round(accuracyWilson($totals['bad_months'], $totals['months'])),
        'seeds' => $totals['seeds'],
        'employee_days' => $totals['days'],
        'punches' => $totals['punches'],
        'per_record_fields_dropped' => $totals['dropped'],
        'fields' => $fields,
        'disagreements' => $totals['disagreements'],
        'ledger_and_payroll_disagreements' => $totals['ledger'],
        'profile' => $profile,
    ];
    file_put_contents(accuracyReportDir().'/summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $knownLines = [];
    foreach ($totals['known'] as $name => $k) {
        $knownLines[] = sprintf(
            '- `%s`: %s. %d disagreements in %d months. By field: %s. By day class: %s. Example %s: engine said %s, oracle said %s.',
            $name, generatedFindings()[$name]['finding'] ?? '', $k['count'], count($k['seeds']), $join($k['fields']), $join($k['classes']),
            $k['example']['key'], generatedShow($k['example']['engine']), generatedShow($k['example']['oracle']),
        );
    }
    $unexplainedCount = count(array_filter($totals['disagreements'], static fn (array $d): bool => $d['known'] === null));

    $md = sprintf(
        "# Generated months against the oracle\n\nBase seed %d. Months requested %d, completed %d. %d employee-days, %d punches. Replay a month alone with `ACCURACY_SEED=<seed> ACCURACY_MONTHS=1`.\n\n"
        ."A unit is an employee-day, except `outcome (per punch)` (a punch), `live (month)` and `conflicts (month)` (a month) and the per-record fields (a record). Compared = agreed + disagreed. A field the oracle marks Undecided is compared with every reading: an engine value inside the readings counts as undecided, one outside them as a disagreement (the last but one column gives inside, then after the slash outside, by rule). A field the oracle has no opinion on is not compared.\n\n"
        ."**Informative** counts only comparisons that could have disagreed: `live` on a day where either side has a record or a punch, `class` on a day that is not a plain workday on either side, `absent` on a workday with no record on a side, `overtime` and `worked_day` where either side has a record, `outcome` where either side is not `ok`, and `breaks`, `check_out`, `conflicts` and `auto_checkout` only where something happened. `late_day` and `early_day` (the ledger's day figures) are compared with the oracle's record-level late and early only on a workday with exactly one live record, as the oracle has no day-level key. `late and early on a day off` checks that the engine's record-level late and early are 0 on a rest day, a holiday or a day before hire or after leaving (the register's value, not the oracle's); on a day of approved leave it counts as undecided under AR-ABS-08.\n\n"
        ."| Field | Compared | Agreed | Disagreed | Agreement | 95%% Wilson | Informative | Informative agreement | Informative 95%% Wilson | Undecided: engine inside / outside readings | No opinion |\n|---|---:|---:|---:|---:|---|---:|---:|---|---|---:|\n%s\n\n"
        ."Months with any disagreement: %d of %d (%s, 95%% Wilson %s); with an unexplained one (not a known finding): %d. Days of one month are not independent, so this is the figure to read first.\n\n"
        ."Per-record fields not compared because the day's `live` was undecided or differed between the sides: %d undecided, %d differed (8 fields per record).\n\n"
        ."Ledger and payroll agree (shared code: a consistency check, not an independent one): %d problems.\n\n"
        ."## Known findings\n\n%s\n\nUnexplained disagreements: %d.\n\n## What was generated\n\n| Behaviour | Days | Months with it |\n|---|---:|---:|\n%s\n\n| Set-up | Months |\n|---|---:|\n%s\n\nDisagreements: %d.\n",
        generatedSeed(), generatedMonths(), $totals['months'], $totals['days'], $totals['punches'], implode("\n", $lines),
        $totals['bad_months'], $totals['months'], $pct($totals['bad_months'], $totals['months']), $wilson($totals['bad_months'], $totals['months']), $totals['unexplained_months'],
        $totals['dropped']['undecided'], $totals['dropped']['different'], count($totals['ledger']),
        $knownLines === [] ? 'None.' : implode("\n", $knownLines), $unexplainedCount,
        implode("\n", $freq), implode("\n", $setupLines), count($totals['disagreements']),
    );
    file_put_contents(accuracyReportDir().'/summary.md', $md);
});

<?php

declare(strict_types=1);

use App\Enums\TimesheetCode;
use App\Services\Attendance\TimesheetLedger;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\StatutoryRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
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
 * person triages it. A field the oracle marks Undecided (the readings of an
 * Undecided rule disagree here) or NoOpinion (the register is silent) is not
 * compared; it is counted, by rule.
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
    static $totals = ['months' => 0, 'days' => 0, 'punches' => 0, 'fields' => [], 'disagreements' => [], 'ledger' => [], 'seeds' => []];

    if ($add !== null) {
        $totals['months']++;
        $totals['days'] += $add['days'];
        $totals['punches'] += $add['punches'];
        $totals['seeds'][] = $add['seed'];
        foreach ($add['rows'] as $row) {
            $f = &$totals['fields'][$row['field']];
            $f ??= ['agreed' => 0, 'disagreed' => 0, 'undecided' => [], 'no_opinion' => 0];
            match ($row['status']) {
                'agreed' => $f['agreed']++,
                'disagreed' => $f['disagreed']++,
                'undecided' => $f['undecided'][$row['rule']] = ($f['undecided'][$row['rule']] ?? 0) + 1,
                'no_opinion' => $f['no_opinion']++,
            };
            unset($f);
        }
        foreach ($add['rows'] as $row) {
            if ($row['status'] === 'disagreed') {
                $totals['disagreements'][] = ['seed' => $add['seed'], 'key' => generatedKey($add['seed'], $row)] + $row;
            }
        }
        foreach ($add['ledger'] as $problem) {
            $totals['ledger'][] = $problem;
        }
    }

    return $totals;
}

/**
 * One leaf: the engine's value against the oracle's.
 *
 * @return array{status: string, rule: ?string}
 */
function generatedLeaf(mixed $engine, mixed $oracle): array
{
    if ($oracle instanceof NoOpinion) {
        return ['status' => 'no_opinion', 'rule' => null];
    }
    if ($oracle instanceof Undecided) {
        return ['status' => 'undecided', 'rule' => $oracle->label()];
    }

    return ['status' => $engine === $oracle ? 'agreed' : 'disagreed', 'rule' => null];
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
 * Every field of every date, engine against oracle.
 *
 * @param  array<string, mixed>  $engine  accuracyEngineAnswer()
 * @param  array<string, mixed>  $oracle  CaseAdapter::run()
 * @param  list<string>  $dates
 * @return list<array<string, mixed>>
 */
function generatedCompare(array $engine, array $oracle, array $dates): array
{
    $rows = [];
    $add = function (string $field, mixed $mine, mixed $theirs, ?string $date = null, ?int $record = null) use (&$rows): void {
        $rows[] = ['field' => $field, 'date' => $date, 'record' => $record, 'engine' => $mine, 'oracle' => $theirs, ...generatedLeaf($mine, $theirs)];
    };

    $add('live (month)', $engine['live'], $oracle['live']);
    $add('conflicts (month)', $engine['conflicts'], $oracle['conflicts']);
    foreach ($engine['outcomes'] as $i => $outcome) {
        // A null outcome is the register giving no answer for that punch.
        $add('outcome (per punch)', $outcome, ($oracle['outcomes'][$i] ?? null) ?? new NoOpinion('the register gives no outcome for this punch'), null, $i + 1);
    }

    foreach ($dates as $date) {
        $e = $engine['days'][$date];
        $o = $oracle['days'][$date];

        $add('live', $e['live'], $o['live'], $date);
        $add('class', $e['class'], $o['class'], $date);
        $add('absent', $e['absent'], $o['absent'], $date);
        $add('overtime', $e['overtime'], generatedBuckets($o['overtime'] instanceof Undecided
            ? new Undecided($o['overtime']->rules, generatedBuckets($o['overtime']->literal))
            : $o['overtime']), $date);
        $add('worked_day', $e['worked_day'], $o['worked_day'], $date);

        // Per-record fields only where both sides found the same number of records.
        if ($o['live'] instanceof NoOpinion || $o['live'] instanceof Undecided || $o['live'] !== $e['live']) {
            continue;
        }
        foreach ($e['records'] as $i => $r) {
            $theirs = $o['records'][$i];
            foreach (['status', 'late', 'early', 'worked', 'check_in', 'check_out', 'breaks'] as $field) {
                $add($field, $r[$field], $theirs[$field], $date, $i);
            }
            $meta = $theirs['metadata'];
            $add('auto_checkout', (bool) ($r['metadata']['auto_checkout'] ?? false), is_array($meta) ? $meta['auto_checkout'] : $meta, $date, $i);
        }
    }

    return $rows;
}

/**
 * The ledger of the same month: consistent, and saying what payroll reads.
 * `attendanceFigures()` carries overtime only, so absences are compared with
 * `unexplainedAbsencesFor()`, as TimesheetLedgerTest does.
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

function generatedShow(mixed $value): string
{
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

    $outcomes = [];
    foreach ($case['punches'] as $i => $punch) {
        $outcomes[] = accuracyPunch($ctx, $punch, "gen-{$seed}-{$i}");
    }

    if (isset($case['setup']['auto_checkout'])) {
        Carbon::setTestNow(accuracyMoment($ctx['day'], $ctx['zone'], $case['auto_run_at']));
        expect(Artisan::call('attendance:auto-checkout'))->toBe(0);
    }

    $first = Carbon::parse($case['day']);
    $dates = [];
    for ($d = 0; $d < $first->daysInMonth; $d++) {
        $dates[] = $first->copy()->addDays($d)->format('Y-m-d');
    }

    $engine = accuracyEngineAnswer($case, $ctx, $outcomes, $dates);
    $oracle = CaseAdapter::run($case, require __DIR__.'/fixtures/shifts.php', $dates);

    $rows = generatedCompare($engine, $oracle, $dates);
    $ledger = generatedLedgerProblems($ctx, $dates);
    Carbon::setTestNow();

    generatedTotals(['seed' => $seed, 'days' => count($dates), 'punches' => count($case['punches']), 'rows' => $rows, 'ledger' => array_map(static fn (string $p): string => $seed.'/'.$p, $ledger)]);

    // One file per disagreeing date (or `month`), ready to become a golden case.
    $files = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'disagreed') {
            $files[$row['date'] ?? 'month'][] = sprintf(
                'field %s%s: engine said %s, oracle said %s',
                $row['field'],
                $row['record'] !== null ? ' #'.($row['record'] + ($row['date'] === null ? 0 : 1)) : '',
                generatedShow($row['engine']),
                generatedShow($row['oracle']),
            );
        }
    }
    foreach ($files as $date => $lines) {
        accuracyWriteCaseFile("{$seed}-{$date}", $case, ["seed {$seed}, {$date}", ...$lines]);
    }
    if ($ledger !== []) {
        accuracyWriteCaseFile("{$seed}-ledger", $case, ["seed {$seed}", ...$ledger]);
    }

    $listed = generatedFindings();
    $seen = [];
    $unlisted = [];
    foreach ($rows as $row) {
        if ($row['status'] === 'disagreed') {
            $key = generatedKey($seed, $row);
            $seen[$key] = true;
            if (! isset($listed[$key])) {
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
        if (str_starts_with($key, $seed.'/') && ! isset($seen[$key])) {
            $stale[] = "{$key} is listed in generated-findings.php but no longer disagrees: remove it";
        }
    }

    expect([...$unlisted, ...$stale])->toBe([], "seed {$seed}: replay with ACCURACY_SEED={$seed} ACCURACY_MONTHS=1; cases are in storage/framework/testing/accuracy");
})->with(generatedDataset());

afterAll(function (): void {
    $totals = generatedTotals();
    if ($totals['months'] === 0) {
        return;
    }

    $fields = [];
    $lines = [];
    foreach ($totals['fields'] as $name => $f) {
        $compared = $f['agreed'] + $f['disagreed'];
        [$low, $high] = accuracyWilson($f['agreed'], $compared);
        $fields[$name] = [
            'compared' => $compared,
            'agreed' => $f['agreed'],
            'disagreed' => $f['disagreed'],
            'undecided' => $f['undecided'],
            'no_opinion' => $f['no_opinion'],
            'agreement_rate' => $compared === 0 ? null : round($f['agreed'] / $compared, 4),
            'wilson95' => [round($low, 4), round($high, 4)],
        ];
        $undecided = [];
        foreach ($f['undecided'] as $rule => $n) {
            $undecided[] = "{$rule} {$n}";
        }
        $lines[] = sprintf(
            '| %s | %d | %d | %d | %s | %d | %s | %s |',
            $name, $compared, $f['agreed'], $f['disagreed'],
            $undecided === [] ? '0' : implode(', ', $undecided), $f['no_opinion'],
            $compared === 0 ? 'n/a' : sprintf('%.2f%%', 100 * $f['agreed'] / $compared),
            sprintf('%.2f%% to %.2f%%', 100 * $low, 100 * $high),
        );
    }

    $report = [
        'base_seed' => generatedSeed(),
        'months' => $totals['months'],
        'seeds' => $totals['seeds'],
        'employee_days' => $totals['days'],
        'punches' => $totals['punches'],
        'fields' => $fields,
        'disagreements' => $totals['disagreements'],
        'ledger_problems' => $totals['ledger'],
    ];
    file_put_contents(accuracyReportDir().'/summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $md = sprintf(
        "# Generated months against the oracle\n\nBase seed %d, %d months, %d employee-days, %d punches. Replay a month alone with `ACCURACY_SEED=<seed> ACCURACY_MONTHS=1`.\n\n"
        ."A unit is an employee-day, except `outcome (per punch)` (a punch), `live (month)` and `conflicts (month)` (a month) and the per-record fields `status` to `auto_checkout` (a record). Compared = agreed + disagreed. Undecided and no-opinion fields are not compared.\n\n"
        ."| Field | Compared | Agreed | Disagreed | Undecided (by rule) | No opinion | Agreement | 95%% Wilson |\n|---|---:|---:|---:|---|---:|---:|---|\n%s\n\nDisagreements: %d. Ledger problems: %d.\n",
        generatedSeed(), $totals['months'], $totals['days'], $totals['punches'], implode("\n", $lines),
        count($totals['disagreements']), count($totals['ledger']),
    );
    file_put_contents(accuracyReportDir().'/summary.md', $md);
});

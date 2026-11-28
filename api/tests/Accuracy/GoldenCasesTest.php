<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tests\Accuracy\Oracle\CaseAdapter;
use Tests\Accuracy\OracleComparer;

require_once __DIR__.'/Support/engine.php';

/*
 * The golden cases: every expected value in tests/Accuracy/cases is typed by
 * hand from docs/ATTENDANCE-RULES.md or the QA pack, before the engine is
 * asked. This file only sends the punches through the engine, with the helpers
 * in Support/engine.php, and compares the answer with the hand values. It
 * implements no rule.
 *
 * A case the engine disagrees with is never edited to pass. It is listed in
 * findings.php, reported as incomplete with the text there, and triaged.
 * A case whose rule is Undecided in the register is skipped, never asserted.
 *
 * Every helper here is global in Pest, so each is prefixed `accuracy`.
 */

/** @return array<string, array{0: array<string, mixed>}> */
function accuracyDataset(): array
{
    $cases = [];
    foreach (glob(__DIR__.'/cases/*.php') ?: [] as $file) {
        foreach (require $file as $case) {
            $cases[$case['id']] = [$case];
        }
    }
    ksort($cases);

    // ACCURACY_CASE=GC-STATUS-002,GC-ZONE-001 runs only those cases. PHPUnit's
    // --filter cannot see a dataset key.
    $only = array_filter(array_map('trim', explode(',', (string) getenv('ACCURACY_CASE'))));

    return $only === [] ? $cases : array_intersect_key($cases, array_flip($only));
}

/** @return array<string, string> case id => what the engine answered */
function accuracyFindings(): array
{
    return require __DIR__.'/findings.php';
}

it('gives the answer typed by hand', function (array $case) {
    $rules = implode(', ', (array) $case['rule']);

    if ($case['undecided'] ?? false) {
        test()->markTestSkipped("{$case['id']}: rule undecided ({$rules}), the register has no answer to assert");
    }

    $ctx = accuracyFixture($case);

    $outcomes = [];
    foreach ($case['punches'] ?? [] as $i => $punch) {
        $outcomes[] = accuracyPunch($ctx, $punch, "acc-{$case['id']}-{$i}");
    }

    if (isset($case['setup']['auto_checkout'])) {
        Carbon::setTestNow(accuracyMoment($ctx['day'], $ctx['zone'], $case['auto_run_at']));
        test()->artisan('attendance:auto-checkout')->assertSuccessful();
    }

    $misses = accuracyCompare($case, $ctx, $outcomes);
    Carbon::setTestNow();

    // The third opinion. When the engine and the hand value disagree, the
    // oracle says which of the two is outvoted.
    $verdict = $misses === [] ? '' : "\n".accuracyVerdict($case);

    $finding = accuracyFindings()[$case['id']] ?? null;
    if ($finding !== null) {
        expect($misses)->not->toBeEmpty("{$case['id']} is listed in findings.php but the engine now agrees with the hand value: remove it from the list");
        test()->markTestIncomplete("RECORDED FINDING {$case['id']} ({$rules}): {$finding}\n".implode("\n", $misses).$verdict);
    }

    expect($misses)->toBe([], "\n".implode("\n", $misses).$verdict);
})->with(accuracyDataset());

/**
 * What the independent oracle (tests/Accuracy/Oracle) says about the same
 * case, read only when the engine has disagreed with a hand value. Two against
 * one names the suspect; it does not prove it.
 *
 * @param  array<string, mixed>  $case
 */
function accuracyVerdict(array $case): string
{
    // The dates are the question asked; the oracle never reads the answers.
    $answer = CaseAdapter::run($case, require __DIR__.'/fixtures/shifts.php', array_keys($case['expect']['days'] ?? []) ?: null);
    $oracle = OracleComparer::compare($case, $answer);

    if ($oracle['mismatches'] === [] && $oracle['undecided'] === []) {
        return 'THREE-WAY: the oracle agrees with every hand value of this case, so the engine is outvoted two to one. Suspect the engine.';
    }

    $lines = array_map(fn (string $m): string => '  oracle: '.$m, $oracle['mismatches']);
    foreach ($oracle['undecided'] as $u) {
        $lines[] = "  oracle: {$u['field']} depends on undecided rule {$u['rule']}";
    }

    if ($oracle['mismatches'] === []) {
        return "THREE-WAY: the oracle has no decided answer here; the field depends on an undecided rule. The register must be ruled on first.\n".implode("\n", $lines);
    }

    return "THREE-WAY: the oracle also disagrees with the hand value here. Suspect the hand value or the register first.\n".implode("\n", $lines);
}

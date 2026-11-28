<?php

declare(strict_types=1);

use Tests\Accuracy\Oracle\CaseAdapter;
use Tests\Accuracy\OracleComparer;

/*
 * The independent oracle (tests/Accuracy/Oracle) against the hand values of
 * every golden case. It touches no database and no clock. A disagreement is
 * either listed in oracle-findings.php (then it shows as incomplete) or fails.
 *
 * Every global function here is prefixed `oracle`: GoldenCasesTest.php declares
 * `accuracy*` functions and Pest functions are global.
 *
 * ORACLE_CASE=GC-A,GC-B runs chosen cases. ORACLE_REPORT=1 prints, per case,
 * the Undecided and Implemented rules the answer relied on.
 */

/** @return array<string, array<string, mixed>> */
function oracleLoadCases(): array
{
    $cases = [];
    foreach (glob(__DIR__.'/cases/*.php') ?: [] as $file) {
        foreach (require $file as $case) {
            $cases[$case['id']] = $case;
        }
    }

    $only = array_filter(array_map('trim', explode(',', (string) getenv('ORACLE_CASE'))));
    if ($only !== []) {
        $cases = array_intersect_key($cases, array_flip($only));
    }

    return $cases;
}

/** @return array<string, array{0: array<string, mixed>}> */
function oracleDataset(): array
{
    return array_map(static fn (array $case): array => [$case], oracleLoadCases());
}

/** @return array<string, array<string, mixed>> */
function oracleShifts(): array
{
    return require __DIR__.'/fixtures/shifts.php';
}

/** @return array<string, string> */
function oracleFindings(): array
{
    return require __DIR__.'/oracle-findings.php';
}

it('gives the answer a person would by hand', function (array $case): void {
    $id = $case['id'];
    $rules = implode(', ', $case['rule']);

    // The dates are a question put to the oracle; the hand values stay here.
    $answer = CaseAdapter::run($case, oracleShifts(), array_keys($case['expect']['days'] ?? []) ?: null);
    $result = OracleComparer::compare($case, $answer);

    if (getenv('ORACLE_REPORT')) {
        fwrite(STDERR, sprintf(
            "%s agreed=%d undecided=[%s] touches-undecided=[%s] implemented=[%s]\n",
            $id,
            $result['agreed'],
            implode(',', array_column($result['undecided'], 'rule')),
            implode(',', $answer['meta']['undecided']),
            implode(',', $answer['meta']['implemented']),
        ));
    }

    if (! empty($case['undecided'])) {
        // The case is not asserted, but the oracle must itself say "undecided".
        expect($result['undecided'])->not->toBeEmpty("[{$id}] rule {$rules}: the case is marked undecided but the oracle gives an answer");
        $this->markTestSkipped("[{$id}] rule undecided: {$rules}");
    }

    $findings = oracleFindings();
    if (isset($findings[$id])) {
        if ($result['mismatches'] === []) {
            $this->fail("[{$id}] is listed in oracle-findings.php but the oracle now agrees with the hand value: remove the entry");
        }
        $this->markTestIncomplete("[{$id}] ORACLE FINDING: {$findings[$id]} | ".implode('; ', $result['mismatches']));
    }

    if ($result['mismatches'] !== []) {
        $this->fail(implode("\n", array_map(
            static fn (string $line): string => "[{$id}] rule {$rules}: {$line}",
            $result['mismatches'],
        )));
    }

    if ($result['agreed'] === 0) {
        $this->markTestSkipped("[{$id}] rule {$rules}: every field is undecided or has no oracle opinion");
    }

    expect($result['mismatches'])->toBe([]);
})->with(oracleDataset());

it('lists no finding for a case that does not exist', function (): void {
    $known = array_keys(oracleLoadCases());
    if (getenv('ORACLE_CASE')) {
        $this->markTestSkipped('a subset of cases was chosen');
    }

    expect(array_diff(array_keys(oracleFindings()), $known))->toBe([]);
});

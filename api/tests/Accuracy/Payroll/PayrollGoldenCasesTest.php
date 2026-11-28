<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tests\Accuracy\Payroll\EngineReader;
use Tests\Accuracy\Payroll\FixtureError;
use Tests\Accuracy\Payroll\PayrollCases;
use Tests\Accuracy\Payroll\PayrollComparer;
use Tests\Accuracy\Payroll\PayrollFixture;
use Tests\Accuracy\Payroll\PayrollVerdict;

/*
 * The payroll golden cases: every expected figure in tests/Accuracy/Payroll/
 * cases is typed by hand from docs/payroll/PAYROLL-RULES.md before the engine
 * is asked. This file builds the organisation and the people (PayrollFixture),
 * runs PayrollEngine through its own preview and approve (EngineReader) and
 * compares two numbers. It implements no payroll rule.
 *
 * A case the engine disagrees with is never edited to pass. It is listed in
 * findings.php, reported as incomplete with the text there, and triaged. A case
 * marked undecided is skipped, never asserted.
 *
 * PAYROLL_CASE=GP-TAX-001,GP-NET-001 runs only those cases.
 */

it('pays what the hand values say', function (array $case): void {
    $id = $case['id'];
    $rules = implode(', ', $case['rule']);

    if ($case['undecided'] ?? false) {
        test()->markTestSkipped("{$id}: rule undecided ({$rules}), the register has no answer to assert");
    }

    try {
        $built = PayrollFixture::build($case);
        $read = EngineReader::read($case, $built);
    } catch (FixtureError $e) {
        test()->fail("[{$id}] rule {$rules}: ".$e->getMessage());
    } finally {
        Carbon::setTestNow();
    }

    if ($read['echo'] !== []) {
        test()->fail("[{$id}] rule {$rules}: FIXTURE ERROR, the engine did not take the inputs as stated:\n  ".implode("\n  ", $read['echo']));
    }

    $result = PayrollComparer::compare($case, $read['answer'], 'engine');
    $misses = [
        ...array_map(static fn (string $m): string => "[{$id}] rule {$rules}: {$m}", $result['mismatches']),
        ...array_map(static fn (string $m): string => "[{$id}] rule {$rules}: CONSISTENCY, {$m}", $read['consistency']),
    ];

    // The third opinion, only when the engine and the hand values disagree.
    $verdict = $result['mismatches'] === [] ? '' : "\n".PayrollVerdict::of($case);

    $finding = PayrollCases::findings()[$id] ?? null;
    if ($finding !== null) {
        expect($misses)->not->toBeEmpty("{$id} is listed in findings.php but the engine now agrees with the hand values: remove it from the list");
        test()->markTestIncomplete("RECORDED FINDING {$id} ({$rules}): {$finding}\n".implode("\n", $misses).$verdict);
    }

    expect($misses)->toBe([], "\n".implode("\n", $misses).$verdict);
    test()->assertGreaterThan(0, $result['agreed'], "[{$id}] no stated field could be compared with the engine's answer: ".implode(', ', $result['no_opinion']));
})->with(fn (): array => PayrollCases::dataset());

it('lists no engine finding for a case that does not exist', function (): void {
    if (getenv('PAYROLL_CASE')) {
        test()->markTestSkipped('a subset of cases was chosen');
    }

    $known = array_column(PayrollCases::raw(), 'id');

    expect(array_diff(array_keys(PayrollCases::findings()), $known))->toBe([]);
});

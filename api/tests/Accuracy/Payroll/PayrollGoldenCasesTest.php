<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tests\Accuracy\Oracle\Payroll\PayslipOracle;
use Tests\Accuracy\Oracle\Undecided;
use Tests\Accuracy\Payroll\EngineReader;
use Tests\Accuracy\Payroll\FixtureError;
use Tests\Accuracy\Payroll\PayrollCases;
use Tests\Accuracy\Payroll\PayrollComparer;
use Tests\Accuracy\Payroll\PayrollFixture;
use Tests\Accuracy\Payroll\PayrollTally;
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
    $withheld = [];

    if (isset(PayrollCases::notCoverable()[$id])) {
        test()->markTestSkipped("{$id}: NOT COVERABLE by the fixture: ".PayrollCases::notCoverable()[$id]);
    }

    // A case marked undecided is still run: every stated field the oracle does
    // not return as Undecided is compared with the engine, and only the fields
    // it marks are withheld (and counted).
    if ($case['undecided'] ?? false) {
        if (! class_exists(PayslipOracle::class)) {
            test()->markTestSkipped("{$id}: rule undecided ({$rules}) and the oracle that says which fields depend on it does not exist yet");
        }
        $oracle = PayslipOracle::compute(PayrollCases::question($case), PayrollCases::statutory());
        foreach ($case['expect'] as $who => $fields) {
            foreach (array_keys($fields) as $field) {
                $mine = $who === 'run' ? ($oracle['run'][$field] ?? null) : ($oracle['employees'][$who][$field] ?? null);
                if ($mine instanceof Undecided) {
                    $withheld[] = "{$who} {$field} ({$mine->label()})";
                    unset($case['expect'][$who][$field]);
                }
            }
            if ($case['expect'][$who] === []) {
                unset($case['expect'][$who]);
            }
        }
        PayrollTally::withheld($id, $withheld);
        if ($case['expect'] === []) {
            test()->markTestSkipped("{$id}: every stated field depends on the undecided rule ({$rules})");
        }
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

    // The list of fields the engine does not carry must not name one the reader answers.
    $unexposed = PayrollCases::engineUnexposed();
    foreach ($read['answer']['employees'] as $key => $fields) {
        foreach (array_keys($fields) as $field) {
            foreach (array_keys($unexposed) as $pattern) {
                if (fnmatch($pattern, (string) $field)) {
                    test()->fail("[{$id}] engine-unexposed.php lists '{$pattern}' but the engine reader answers {$key} {$field}: take it off the list");
                }
            }
        }
    }

    $result = PayrollComparer::compare($case, $read['answer'], 'engine', $unexposed);
    PayrollTally::notComparable($result['not_comparable']);

    $misses = [
        ...array_map(static fn (string $m): string => "[{$id}] rule {$rules}: {$m}", $result['mismatches']),
        ...array_map(static fn (string $m): string => "[{$id}] rule {$rules}: CONSISTENCY, {$m}", $read['consistency']),
        ...array_map(static fn (string $m): string => "[{$id}] rule {$rules}: NOT ANSWERED by the engine reader, and not on engine-unexposed.php: {$m}", $result['unanswered']),
    ];

    // The third opinion, only when the engine and the hand values disagree.
    $verdict = $result['mismatches'] === [] ? '' : "\n".PayrollVerdict::of($case);

    $finding = PayrollCases::findings()[$id] ?? null;
    if ($finding !== null) {
        expect($misses)->not->toBeEmpty("{$id} is listed in findings.php but the engine now agrees with the hand values: remove it from the list");
        test()->markTestIncomplete("RECORDED FINDING {$id} ({$rules}): {$finding}\n".implode("\n", $misses).$verdict);
    }

    expect($misses)->toBe([], "\n".implode("\n", $misses).$verdict);
    test()->assertGreaterThan(0, $result['agreed'], "[{$id}] no stated field could be compared with the engine's answer");
})->with(fn (): array => PayrollCases::dataset());

it('lists no engine finding for a case that does not exist', function (): void {
    if (getenv('PAYROLL_CASE')) {
        test()->markTestSkipped('a subset of cases was chosen');
    }

    $known = array_column(PayrollCases::raw(), 'id');

    expect(array_diff([...array_keys(PayrollCases::findings()), ...array_keys(PayrollCases::notCoverable())], $known))->toBe([]);
});

it('reports what it could not compare against the engine', function (): void {
    fwrite(STDERR, '
'.PayrollTally::report().'
');
    expect(true)->toBeTrue();
});

<?php

declare(strict_types=1);

use Tests\Accuracy\Oracle\Payroll\PayslipOracle;
use Tests\Accuracy\Payroll\PayrollCases;
use Tests\Accuracy\Payroll\PayrollComparer;

/*
 * The independent payroll oracle (tests/Accuracy/Oracle/Payroll) against the
 * hand values of every case. It touches no database and no clock. While the
 * oracle does not exist the tests skip with a message; they start running by
 * themselves when PayslipOracle appears.
 *
 * PAYROLL_CASE=GP-TAX-001 runs chosen cases.
 */

it('gives the answer an accountant would by hand', function (array $case): void {
    if (! class_exists(PayslipOracle::class)) {
        test()->markTestSkipped('the payroll oracle (Tests\Accuracy\Oracle\Payroll\PayslipOracle) does not exist yet');
    }

    $id = $case['id'];
    $rules = implode(', ', $case['rule']);

    // The question never carries the answers.
    $answer = PayslipOracle::compute(PayrollCases::question($case), PayrollCases::statutory());
    $result = PayrollComparer::compare($case, $answer);

    if (! empty($case['undecided'])) {
        expect($result['undecided'])->not->toBeEmpty("[{$id}] rule {$rules}: the case is marked undecided but the oracle answers every field");
        test()->markTestSkipped("[{$id}] rule undecided: {$rules}");
    }

    if ($result['undecided'] !== []) {
        $named = implode('; ', array_map(static fn (array $u): string => "{$u['field']} depends on {$u['rule']}", $result['undecided']));
        test()->fail("[{$id}] rule {$rules}: the case is not marked undecided but the oracle returns Undecided: {$named}");
    }

    $findings = PayrollCases::oracleFindings();
    if (isset($findings[$id])) {
        if ($result['mismatches'] === []) {
            test()->fail("[{$id}] is listed in oracle-findings.php but the oracle now agrees with the hand values: remove the entry");
        }
        test()->markTestIncomplete("[{$id}] ORACLE FINDING: {$findings[$id]} | ".implode('; ', $result['mismatches']));
    }

    if ($result['mismatches'] !== []) {
        test()->fail(implode("\n", array_map(static fn (string $line): string => "[{$id}] rule {$rules}: {$line}", $result['mismatches'])));
    }

    if ($result['agreed'] === 0) {
        test()->markTestSkipped("[{$id}] rule {$rules}: every field has no oracle opinion");
    }

    expect($result['mismatches'])->toBe([]);
})->with(fn (): array => PayrollCases::dataset());

it('lists no oracle finding for a case that does not exist', function (): void {
    if (getenv('PAYROLL_CASE')) {
        test()->markTestSkipped('a subset of cases was chosen');
    }

    expect(array_diff(array_keys(PayrollCases::oracleFindings()), array_column(PayrollCases::raw(), 'id')))->toBe([]);
});

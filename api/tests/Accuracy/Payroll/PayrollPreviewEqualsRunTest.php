<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tests\Accuracy\Payroll\EngineReader;
use Tests\Accuracy\Payroll\FixtureError;
use Tests\Accuracy\Payroll\PayrollCases;
use Tests\Accuracy\Payroll\PayrollFixture;

/*
 * PYR-RUN-01: a preview computes every figure with the same code and rules as a
 * run and writes nothing, so its payslips equal the run's to the cent. No hand
 * value is involved, so every coverable case is used, undecided ones included.
 *
 * PAYROLL_CASE=GP-TAX-001 runs chosen cases.
 */

it('previews exactly what the run then pays, and writes nothing', function (array $case): void {
    $id = $case['id'];

    if (isset(PayrollCases::notCoverable()[$id])) {
        test()->markTestSkipped("{$id}: NOT COVERABLE by the fixture: ".PayrollCases::notCoverable()[$id]);
    }

    try {
        $built = PayrollFixture::build($case);
        $result = EngineReader::previewAgainstRun($case, $built);
    } catch (FixtureError $e) {
        test()->fail("[{$id}] PYR-RUN-01: ".$e->getMessage());
    } finally {
        Carbon::setTestNow();
    }

    if (getenv('PAYROLL_REPORT')) {
        fwrite(STDERR, "{$id} people={$result['people']} fields={$result['fields']}
");
    }

    $lines = [
        ...array_map(static fn (string $m): string => "[{$id}] PYR-RUN-01: {$m}", $result['wrote']),
        ...array_map(static fn (string $m): string => "[{$id}] PYR-RUN-01: preview and run differ: {$m}", $result['differences']),
    ];

    expect($lines)->toBe([], "\n".implode("\n", $lines));
})->with(fn (): array => PayrollCases::dataset());

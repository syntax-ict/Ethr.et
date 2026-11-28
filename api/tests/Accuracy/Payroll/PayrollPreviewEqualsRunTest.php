<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tests\Accuracy\Payroll\EngineReader;
use Tests\Accuracy\Payroll\FixtureError;
use Tests\Accuracy\Payroll\PayrollCases;
use Tests\Accuracy\Payroll\PayrollFixture;

/*
 * PYR-RUN-01, the part a golden case cannot check: a preview persists nothing,
 * and what a run then stores is what the preview showed.
 *
 * This does NOT check a calculation. preview() and a run call the same
 * computeEntry() on the same prepared data, so they would agree on a wrong
 * figure too; the hand-valued cases are what check the figures. What this
 * catches is a preview that writes a run, an entry or a balance, and a run
 * that stores something other than the preview computed.
 *
 * Every coverable case is used, undecided ones included, since no hand value is
 * involved. PAYROLL_CASE=GP-TAX-001 runs chosen cases.
 */

it('persists nothing on preview, and stores in the run what the preview showed', function (array $case): void {
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
        fwrite(STDERR, "{$id} people={$result['people']} fields={$result['fields']}\n");
    }

    $lines = [
        ...array_map(static fn (string $m): string => "[{$id}] PYR-RUN-01: {$m}", $result['wrote']),
        ...array_map(static fn (string $m): string => "[{$id}] PYR-RUN-01: preview and run differ: {$m}", $result['differences']),
    ];

    // A case that produced no entries must not pass: the people compared are
    // the people the case says are in the run.
    $inRun = 0;
    foreach ($case['expect'] as $who => $fields) {
        $inRun += $who !== 'run' && ($fields['in_run'] ?? null) === 1 ? 1 : 0;
    }
    $allStated = count(array_diff(array_keys($case['expect']), ['run'])) === count($case['employees']);
    if ($allStated ? $result['people'] !== $inRun : $result['people'] < $inRun) {
        $lines[] = "[{$id}] PYR-RUN-01: {$result['people']} people were compared but the case expects {$inRun} in the run";
    }
    if ($inRun > 0 && $result['fields'] === 0) {
        $lines[] = "[{$id}] PYR-RUN-01: no field was compared";
    }

    expect($lines)->toBe([], "\n".implode("\n", $lines));
})->with(fn (): array => PayrollCases::dataset());

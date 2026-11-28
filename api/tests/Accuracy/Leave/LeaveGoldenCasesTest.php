<?php

declare(strict_types=1);

use Carbon\Carbon;
use Tests\Accuracy\Leave\LeaveCases;
use Tests\Accuracy\Leave\LeaveRunner;

/*
 * The leave golden cases: every expected value in tests/Accuracy/Leave/cases
 * is typed by hand from docs/LEAVE-RULES.md before the engine is asked. This
 * file builds the world a case describes (LeaveFixture), asks the engine
 * through its own entry points, and compares two numbers. It implements no
 * rule.
 *
 * A case the engine disagrees with is never edited to pass. It is listed in
 * Leave/findings.php, reported as incomplete with the text there, and
 * triaged. A case whose rule is Undecided in the register is skipped, never
 * asserted; a case that waits on a signed item of the register's "Waiting for
 * a decision" list is typed now and skipped until the signature exists.
 */

it('has every leave case in the shape the loader requires', function () {
    $cases = LeaveCases::all();

    expect($cases)->not->toBeEmpty();
    foreach (array_keys(LeaveCases::findings()) as $id) {
        expect(array_key_exists($id, $cases))->toBeTrue("findings.php names {$id}, which is not a case");
    }
});

it('gives the leave answer typed by hand', function (array $case) {
    $rules = implode(', ', $case['rule']);

    if ($case['undecided'] ?? false) {
        test()->markTestSkipped("{$case['id']}: rule undecided ({$rules}), the register has no answer to assert");
    }
    if (array_key_exists('waits_on', $case) && ($case['signed'] ?? null) === null) {
        test()->markTestSkipped("{$case['id']}: waiting for a signed expected value, item {$case['waits_on']} ({$rules})");
    }

    $expect = array_replace_recursive($case['expect'], $case['readings'][$case['ruling'] ?? ''] ?? []);
    $misses = LeaveRunner::run($case, $expect, $this);
    Carbon::setTestNow();

    $finding = LeaveCases::findings()[$case['id']] ?? null;
    if ($finding !== null) {
        expect($misses)->not->toBeEmpty("{$case['id']} is listed in Leave/findings.php but the engine now agrees with the hand value: remove it from the list");
        test()->markTestIncomplete("RECORDED FINDING {$case['id']} ({$rules}): {$finding}\n".implode("\n", $misses));
    }

    expect($misses)->toBe([], "\n".implode("\n", $misses));
})->with(fn (): array => LeaveCases::dataset());

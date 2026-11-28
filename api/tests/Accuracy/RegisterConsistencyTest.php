<?php

declare(strict_types=1);

use Tests\Accuracy\Registers;

/*
 * The registers (docs/ATTENDANCE-RULES.md, docs/payroll/PAYROLL-RULES.md,
 * docs/LEAVE-RULES.md and the calendar rules in docs/production testing/14-*)
 * and the cases that pin them must say the same thing. An engine whose case
 * directory does not exist yet has nothing to check.
 */

dataset('register_engines', array_keys(Registers::ENGINES));

it('reads every rule row of the register', function (string $prefix): void {
    $register = Registers::register($prefix);

    expect($register['unparsed'])->toBe([]);
    expect($register['rules'])->not->toBe([]);
})->with('register_engines');

it('cites only rules that exist, with the right prefix', function (string $prefix): void {
    $cited = Registers::citations($prefix);
    if ($cited === null) {
        $this->markTestSkipped("no case directory for {$prefix} yet");
    }

    $rules = Registers::register($prefix)['rules'];
    $bad = [];
    foreach ($cited as $name => $case) {
        foreach ($case['rules'] as $id) {
            if (! str_starts_with($id, $prefix.'-')) {
                $bad[] = "{$name}: {$id} does not start with {$prefix}-";
            } elseif (! isset($rules[$id])) {
                $bad[] = "{$name}: {$id} is not in the register";
            }
        }
    }

    expect($bad)->toBe([]);
})->with('register_engines');

it('has a case, or a reasoned exemption, for every Decided rule', function (string $prefix): void {
    $cited = Registers::citations($prefix);
    if ($cited === null) {
        $this->markTestSkipped("no case directory for {$prefix} yet");
    }

    $covered = [];
    foreach ($cited as $case) {
        foreach ($case['rules'] as $id) {
            $covered[$id] = true;
        }
    }

    $exempt = array_filter(Registers::exemptions(), static fn (string $id): bool => str_starts_with($id, $prefix.'-'), ARRAY_FILTER_USE_KEY);
    $uncovered = [];
    foreach (Registers::register($prefix)['rules'] as $id => $rule) {
        if ($rule['state'] === 'Decided' && ! isset($covered[$id]) && ! isset($exempt[$id])) {
            $uncovered[] = $id;
        }
    }

    expect($uncovered)->toBe([], 'Decided rules with no case and no entry in register-exemptions.php');
})->with('register_engines');

it('keeps no exemption that is stale or unexplained', function (): void {
    $bad = [];
    foreach (Registers::exemptions() as $id => $reason) {
        $prefix = explode('-', $id)[0];
        if (! isset(Registers::ENGINES[$prefix])) {
            $bad[] = "{$id}: unknown prefix";

            continue;
        }
        $rule = Registers::register($prefix)['rules'][$id] ?? null;
        if ($rule === null) {
            $bad[] = "{$id}: not in the register";
        } elseif ($rule['state'] !== 'Decided') {
            $bad[] = "{$id}: is {$rule['state']}, only Decided rules need an exemption";
        }
        if (strlen(trim($reason)) < 20) {
            $bad[] = "{$id}: the reason is missing or too short";
        }

        foreach (Registers::citations($prefix) ?? [] as $name => $case) {
            if (in_array($id, $case['rules'], true)) {
                $bad[] = "{$id}: exempt, but {$name} cites it; delete the exemption";
                break;
            }
        }
    }

    expect($bad)->toBe([]);
});

it('marks a leave case that waits on an Undecided rule', function (): void {
    $cited = Registers::citations('LVR');
    if ($cited === null) {
        $this->markTestSkipped('no case directory for LVR yet');
    }

    $rules = Registers::register('LVR')['rules'];
    $bad = [];
    foreach ($cited as $name => $case) {
        foreach ($case['rules'] as $id) {
            if (isset($rules[$id])
                && preg_match('/Value: (LVR-[A-Z0-9]+-\d+)/', $rules[$id]['text'], $m) === 1
                && str_starts_with($rules[$m[1]]['state'] ?? '', 'Undecided')
                && ! $case['waits_on']) {
                $bad[] = "{$name}: cites {$id}, whose value is the Undecided {$m[1]}, and has no waits_on";
            }
        }
    }

    expect($bad)->toBe([]);
});

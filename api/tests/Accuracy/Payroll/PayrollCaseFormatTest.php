<?php

declare(strict_types=1);

use Tests\Accuracy\Payroll\PayrollCases;

/*
 * The shape of every payroll case file, so a mistyped field fails instead of
 * passing unseen. No engine, no database.
 */

it('accepts every case file as written', function (): void {
    $errors = [];
    foreach (PayrollCases::files() as $file) {
        $cases = require $file;
        if (! is_array($cases) || ! array_is_list($cases)) {
            $errors[] = basename($file).': must return a list of cases';
        }
    }
    foreach (PayrollCases::raw() as $case) {
        $errors = [...$errors, ...PayrollCases::validate($case)];
    }

    expect($errors)->toBe([]);
});

it('gives every case an id of its own', function (): void {
    $ids = array_map(static fn (array $case): string => (string) ($case['id'] ?? ''), PayrollCases::raw());

    expect(array_diff_key($ids, array_unique($ids)))->toBe([]);
});

it('refuses a case that is not as the format says', function (): void {
    $example = null;
    foreach (PayrollCases::raw() as $case) {
        if ($case['id'] === 'GP-NET-001') {
            $example = $case;
        }
    }
    expect($example)->not->toBeNull('the worked example GP-NET-001 is missing');
    expect(PayrollCases::validate($example))->toBe([]);

    $bad = [
        'an unknown key' => static fn (array $c): array => [...$c, 'colour' => 'red'],
        'an unknown expected field' => static function (array $c): array {
            $c['expect']['G7']['netto'] = 1;
            $c['working']['G7'][] = 'netto = 1';

            return $c;
        },
        'a float' => static function (array $c): array {
            $c['expect']['G7']['net'] = 749000.0;

            return $c;
        },
        'a field with no working line' => static function (array $c): array {
            $c['working']['G7'] = array_values(array_filter($c['working']['G7'], static fn (string $l): bool => ! str_starts_with($l, 'net = ')));

            return $c;
        },
        'no pagume_days' => static function (array $c): array {
            unset($c['period']['pagume_days']);

            return $c;
        },
        'a lateness field with deductions off' => static function (array $c): array {
            $c['expect']['G7']['lateness_cap'] = 0;
            $c['working']['G7'][] = 'lateness_cap = 0';

            return $c;
        },
        'a field beside in_run = 0' => static function (array $c): array {
            $c['expect']['G7']['in_run'] = 0;

            return $c;
        },
        'a run field on a preview' => static function (array $c): array {
            $c['expect']['run']['run.entries'] = 1;
            $c['working']['run'] = ['run.entries = 1'];

            return $c;
        },
        'a bad id' => static fn (array $c): array => [...$c, 'id' => 'GP-NET-1'],
        'an allowance that does not exist' => static function (array $c): array {
            $c['expect']['G7']['allowance.bonus'] = 1;
            $c['working']['G7'][] = 'allowance.bonus = 1';

            return $c;
        },
        'a fraction of a day that is not a tenth' => static function (array $c): array {
            $c['employees']['G7']['inputs']['unpaid_leave_thousandths'] = 2050;

            return $c;
        },
    ];

    foreach ($bad as $what => $make) {
        expect(PayrollCases::validate($make($example)))->not->toBe([], "the validator did not refuse: {$what}");
    }
});

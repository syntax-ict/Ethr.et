<?php

declare(strict_types=1);

use Tests\Accuracy\Leave\LeaveProperties;

/*
 * Properties of the leave engine over small tables of generated inputs from
 * one fixed seed (Random\Randomizer with Mt19937). Each reports the seed, the
 * number of cases and the number of disagreements, and no percentage: a
 * disagreement is a result to report, and a property is never weakened to
 * pass. The smallest failing input is named in the failure.
 */

$report = function (string $name, array $result): void {
    fwrite(STDERR, "\n".LeaveProperties::line($name, $result)."\n");
    expect($result['failures'])->toBe([], LeaveProperties::line($name, $result)."\n".LeaveProperties::smallest($result));
};

it('does not change the days charged when a request is split in two, and never charges a negative or a longer count than the span', function () use ($report) {
    $report('splitting', LeaveProperties::splitting($this));
});

it('never raises the days charged by adding a holiday, and lowers them by one when the date was charged', function () use ($report) {
    $report('holidays', LeaveProperties::holidaysNeverRaise($this));
});

it('never lowers the accrual of a later month of a year below an earlier one', function () use ($report) {
    $report('accrual', LeaveProperties::accrualNeverFalls($this));
});

it('changes nothing when accrual or carry-forward is run twice', function () use ($report) {
    $report('repeat', LeaveProperties::repeatsChangeNothing($this));
});

<?php

declare(strict_types=1);

use App\Services\Payroll\OvertimeCalculator;

dataset('overtime_types', [
    'normal (1.25x)' => ['normal', 1.25],
    'night (1.5x)' => ['night', 1.5],
    'holiday (2.0x)' => ['holiday', 2.0],
    'holiday_night (2.5x)' => ['holiday_night', 2.5],
]);

test('applies correct multiplier for each overtime type', function (string $type, float $multiplier) {
    $calc = new OvertimeCalculator;

    $salary = 480_000;
    $workingDays = 26;
    $hoursPerDay = 8;
    $overtimeMinutes = 480; // 8 hours

    $result = $calc->calculate($salary, $workingDays, $hoursPerDay, $overtimeMinutes, $type);

    $hourlyRate = $salary / ($workingDays * $hoursPerDay);
    $expected = (int) round($hourlyRate * 8 * $multiplier);

    expect($result)->toBe($expected);
})->with('overtime_types');

test('returns zero for zero overtime minutes', function () {
    $calc = new OvertimeCalculator;
    expect($calc->calculate(480_000, 26, 8, 0))->toBe(0);
});

test('returns zero for negative overtime minutes', function () {
    $calc = new OvertimeCalculator;
    expect($calc->calculate(480_000, 26, 8, -60))->toBe(0);
});

test('returns zero for zero working days', function () {
    $calc = new OvertimeCalculator;
    expect($calc->calculate(480_000, 0, 8, 60))->toBe(0);
});

test('returns zero for zero hours per day', function () {
    $calc = new OvertimeCalculator;
    expect($calc->calculate(480_000, 26, 0, 60))->toBe(0);
});

test('defaults to normal rate for unknown type', function () {
    $calc = new OvertimeCalculator;

    $normal = $calc->calculate(480_000, 26, 8, 120, 'normal');
    $unknown = $calc->calculate(480_000, 26, 8, 120, 'invalid_type');

    expect($unknown)->toBe($normal);
});

test('handles partial hours correctly', function () {
    $calc = new OvertimeCalculator;
    $result = $calc->calculate(480_000, 26, 8, 90);

    $hourlyRate = 480_000 / (26 * 8);
    $expected = (int) round($hourlyRate * 1.5 * 1.25);

    expect($result)->toBe($expected);
});

test('holiday night pays double the normal rate', function () {
    $calc = new OvertimeCalculator;

    $normal = $calc->calculate(480_000, 26, 8, 120, 'normal');
    $holidayNight = $calc->calculate(480_000, 26, 8, 120, 'holiday_night');

    expect($holidayNight)->toBe((int) round($normal * 2.5 / 1.25));
});

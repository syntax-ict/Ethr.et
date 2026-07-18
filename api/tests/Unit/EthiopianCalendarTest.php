<?php

declare(strict_types=1);

use App\Services\Calendar\EthiopianCalendar;
use Carbon\Carbon;

// ── Reference conversion pairs (verified via Julian Day Number) ──
// These anchor the epoch and span several Ethiopian months so a regression in
// the month/day arithmetic (not just the year offset) would surface.

dataset('reference_pairs', [
    // [gregorian Y-m-d, ethYear, ethMonth, ethDay]
    'New Year 2017 EC' => ['2024-09-11', 2017, 1, 1],
    'New Year 2016 EC (post-leap, Sept 12)' => ['2023-09-12', 2016, 1, 1],
    'New Year 2015 EC' => ['2022-09-11', 2015, 1, 1],
    'Meskel 2017 EC' => ['2024-09-27', 2017, 1, 17],
    'Genna (Tahsas 29) 2017 EC' => ['2025-01-07', 2017, 4, 29],
    'Pagume 1, 2015 EC (leap)' => ['2023-09-06', 2015, 13, 1],
    'Pagume 6, 2015 EC (leap, last day)' => ['2023-09-11', 2015, 13, 6],
    'Pagume 5, 2016 EC (common, last day)' => ['2024-09-10', 2016, 13, 5],
    'mid-Sene 2016 EC' => ['2024-06-15', 2016, 10, 8],
]);

test('ethiopianToGregorian matches known reference dates', function (string $greg, int $y, int $m, int $d) {
    $cal = new EthiopianCalendar;
    expect($cal->ethiopianToGregorian($y, $m, $d)->toDateString())->toBe($greg);
})->with('reference_pairs');

test('gregorianToEthiopian matches known reference dates', function (string $greg, int $y, int $m, int $d) {
    $cal = new EthiopianCalendar;
    expect($cal->gregorianToEthiopian(Carbon::parse($greg)))
        ->toBe(['year' => $y, 'month' => $m, 'day' => $d]);
})->with('reference_pairs');

// ── Round-trip identity across every Ethiopian month ──

test('gregorian -> ethiopian -> gregorian is the identity', function () {
    $cal = new EthiopianCalendar;
    $cursor = Carbon::parse('2020-01-01');
    $end = Carbon::parse('2030-12-31');

    while ($cursor->lte($end)) {
        $eth = $cal->gregorianToEthiopian($cursor);
        $back = $cal->ethiopianToGregorian($eth['year'], $eth['month'], $eth['day']);

        expect($back->toDateString())->toBe($cursor->toDateString());

        $cursor->addDays(17); // step through all 13 months without testing every day
    }
});

test('ethiopian -> gregorian -> ethiopian is the identity, including Pagume', function () {
    $cal = new EthiopianCalendar;

    foreach ([2014, 2015, 2016, 2017] as $year) {
        for ($month = 1; $month <= 13; $month++) {
            $maxDay = $month === 13 ? $cal->daysInPagume($year) : 30;
            foreach ([1, $maxDay] as $day) {
                $greg = $cal->ethiopianToGregorian($year, $month, $day);
                expect($cal->gregorianToEthiopian($greg))
                    ->toBe(['year' => $year, 'month' => $month, 'day' => $day]);
            }
        }
    }
});

// ── Leap years and Pagume length ──

test('leap year detection follows the (year mod 4 == 3) rule', function () {
    $cal = new EthiopianCalendar;

    expect($cal->isLeapYear(2015))->toBeTrue();  // 2015 % 4 == 3
    expect($cal->isLeapYear(2011))->toBeTrue();
    expect($cal->isLeapYear(2016))->toBeFalse();
    expect($cal->isLeapYear(2017))->toBeFalse();
    expect($cal->isLeapYear(2018))->toBeFalse();
});

test('Pagume has 6 days in a leap year and 5 otherwise', function () {
    $cal = new EthiopianCalendar;

    expect($cal->daysInPagume(2015))->toBe(6); // leap
    expect($cal->daysInPagume(2016))->toBe(5); // common
});

test('a leap Ethiopian year spans 366 days and a common year 365', function () {
    $cal = new EthiopianCalendar;

    $leapStart = $cal->ethiopianToGregorian(2015, 1, 1);
    $leapNext = $cal->ethiopianToGregorian(2016, 1, 1);
    expect((int) $leapStart->diffInDays($leapNext))->toBe(366);

    $commonStart = $cal->ethiopianToGregorian(2016, 1, 1);
    $commonNext = $cal->ethiopianToGregorian(2017, 1, 1);
    expect((int) $commonStart->diffInDays($commonNext))->toBe(365);
});

// ── Pagume-day counting over a Gregorian payroll period ──

test('pagumeDaysInRange counts 6 days for a leap-year Pagume period', function () {
    $cal = new EthiopianCalendar;

    // Pagume 2015 EC == 2023-09-06 .. 2023-09-11 (6 days, leap year)
    $result = $cal->pagumeDaysInRange(Carbon::parse('2023-09-06'), Carbon::parse('2023-09-11'));

    expect($result['days'])->toBe(6);
    expect($result['ethiopian_year'])->toBe(2015);
    expect($result['pagume_length'])->toBe(6);
});

test('pagumeDaysInRange counts 5 days for a common-year Pagume period', function () {
    $cal = new EthiopianCalendar;

    // Pagume 2016 EC == 2024-09-06 .. 2024-09-10 (5 days, common year)
    $result = $cal->pagumeDaysInRange(Carbon::parse('2024-09-06'), Carbon::parse('2024-09-10'));

    expect($result['days'])->toBe(5);
    expect($result['ethiopian_year'])->toBe(2016);
    expect($result['pagume_length'])->toBe(5);
});

test('pagumeDaysInRange returns zero for an ordinary month with no Pagume overlap', function () {
    $cal = new EthiopianCalendar;

    $result = $cal->pagumeDaysInRange(Carbon::parse('2024-06-01'), Carbon::parse('2024-06-30'));

    expect($result['days'])->toBe(0);
    expect($result['ethiopian_year'])->toBeNull();
    expect($result['pagume_length'])->toBeNull();
});

test('pagumeDaysInRange counts only the overlapping tail when a period straddles Pagume', function () {
    $cal = new EthiopianCalendar;

    // Aug 25 - Sept 8, 2023: Pagume 2015 EC begins 2023-09-06, so Sept 6,7,8 overlap.
    $result = $cal->pagumeDaysInRange(Carbon::parse('2023-08-25'), Carbon::parse('2023-09-08'));

    expect($result['days'])->toBe(3);
    expect($result['ethiopian_year'])->toBe(2015);
    expect($result['pagume_length'])->toBe(6);
});

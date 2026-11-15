<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\ScheduleSlot;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The schedule screen promises 06:00 every day, every Monday, or on the 1st.
 * Runs used to be a day, week or month after the previous one, and the first
 * at 06:00 UTC, which is 09:00 in Addis Ababa.
 */

function addis(string $calendar = 'gregorian'): Tenant
{
    return new Tenant(['timezone' => 'Africa/Addis_Ababa', 'settings' => ['calendar' => $calendar]]);
}

test('the next slot is 06:00 in the organisation\'s zone', function (string $frequency, string $now, string $next) {
    expect(ScheduleSlot::next($frequency, addis(), CarbonImmutable::parse($now))->toIso8601String())
        ->toBe(CarbonImmutable::parse($next)->toIso8601String());
})->with([
    // 06:00 Addis is 03:00 UTC.
    'daily, before today\'s slot' => ['daily', '2026-11-01T01:00:00Z', '2026-11-01T03:00:00Z'],
    'daily, after today\'s slot' => ['daily', '2026-11-01T03:20:00Z', '2026-11-02T03:00:00Z'],
    'weekly, on a Sunday' => ['weekly', '2026-11-01T10:00:00Z', '2026-11-02T03:00:00Z'],
    'weekly, on Monday after the slot' => ['weekly', '2026-11-02T04:00:00Z', '2026-11-09T03:00:00Z'],
    'monthly' => ['monthly', '2026-11-01T05:00:00Z', '2026-12-01T03:00:00Z'],
]);

test('a monthly slot is the first day of the month of the organisation calendar', function (string $now, string $next) {
    expect(ScheduleSlot::next('monthly', addis('ethiopian'), CarbonImmutable::parse($now))->toIso8601String())
        ->toBe(CarbonImmutable::parse($next)->toIso8601String());
})->with([
    // Tikimt 1, 2019 is 11 October 2026, and Hidar 1 is 10 November: 03:00 UTC is 06:00 Addis.
    'inside Tikimt' => ['2026-11-01T05:00:00Z', '2026-11-10T03:00:00Z'],
    'before the slot on a first day' => ['2026-11-10T01:00:00Z', '2026-11-10T03:00:00Z'],
    'after the slot on a first day' => ['2026-11-10T04:00:00Z', '2026-12-10T03:00:00Z'],
    // Pagume 2019 (6 to 11 September 2027, a leap year) is followed by Meskerem 1, 2020.
    'inside Pagume' => ['2027-09-08T10:00:00Z', '2027-09-12T03:00:00Z'],
    // Thirteen runs a year is intended: every month is reported once, and the Meskerem 1 run covers Pagume.
    'after Nehase 1 comes Pagume 1' => ['2027-08-07T04:00:00Z', '2027-09-06T03:00:00Z'],
    'after Pagume 1 comes Meskerem 1' => ['2027-09-06T04:00:00Z', '2027-09-12T03:00:00Z'],
    'the day before the new year' => ['2027-09-11T10:00:00Z', '2027-09-12T03:00:00Z'],
]);

test('a monthly slot on the Gregorian calendar is still the 1st', function () {
    expect(ScheduleSlot::next('monthly', addis(), CarbonImmutable::parse('2026-12-31T23:00:00Z'))->toIso8601String())
        ->toBe(CarbonImmutable::parse('2027-01-01T03:00:00Z')->toIso8601String());
});

test('a run that is late does not drift the next one', function () {
    // The hourly runner picked the report up at 06:59; tomorrow is still 06:00.
    expect(ScheduleSlot::next('daily', addis(), CarbonImmutable::parse('2026-11-01T03:59:00Z'))->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-11-02T03:00:00Z')->toIso8601String());
});

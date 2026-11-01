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

function addis(): Tenant
{
    return new Tenant(['timezone' => 'Africa/Addis_Ababa']);
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

test('a run that is late does not drift the next one', function () {
    // The hourly runner picked the report up at 06:59; tomorrow is still 06:00.
    expect(ScheduleSlot::next('daily', addis(), CarbonImmutable::parse('2026-11-01T03:59:00Z'))->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-11-02T03:00:00Z')->toIso8601String());
});

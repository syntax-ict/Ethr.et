<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\ReaderDates;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The server writes dates and times the way the reader's screen does
 * (src/src/test/ethiopian-time.test.tsx pins the same readings), so an
 * e-mail and the page it links to agree.
 */

function reader(string $calendar, string $clock, string $locale): ReaderDates
{
    return new ReaderDates($calendar, $clock, $locale, 'Africa/Addis_Ababa');
}

test('a date is written in the reader\'s calendar and language', function (string $calendar, string $locale, string $written) {
    expect(reader($calendar, '24h', $locale)->date('2026-11-01'))->toBe($written);
})->with([
    ['ethiopian', 'en', 'Tikimt 22, 2019'],
    ['ethiopian', 'am', 'ጥቅምት 22, 2019'],
    ['gregorian', 'en', '01 Nov 2026'],
]);

test('an instant falls on the date it is in Addis Ababa, not in UTC', function () {
    // 22:30 UTC on 31 October is 01:30 on 1 November in Addis Ababa.
    expect(reader('gregorian', '24h', 'en')->date('2026-10-31T22:30:00Z'))->toBe('01 Nov 2026');
});

test('a time is on the reader\'s clock, in the organisation\'s zone', function (string $instant, string $clock, string $locale, string $written) {
    expect(reader('ethiopian', $clock, $locale)->time($instant))->toBe($written);
})->with([
    ['2026-10-20T03:00:00Z', 'ethiopian', 'am', '12:00 ጠዋት'],
    ['2026-10-20T04:30:00Z', 'ethiopian', 'am', '1:30 ጠዋት'],
    ['2026-10-20T09:00:00Z', 'ethiopian', 'am', '6:00 ቀን'],
    ['2026-10-20T15:00:00Z', 'ethiopian', 'am', '12:00 ምሽት'],
    ['2026-10-20T21:00:00Z', 'ethiopian', 'am', '6:00 ሌሊት'],
    ['2026-10-20T04:30:00Z', 'ethiopian', 'en', '1:30 morning'],
    ['2026-10-20T04:30:00Z', '24h', 'am', '07:30'],
]);

test('a stored shift time is written on the reader\'s clock', function () {
    expect(reader('ethiopian', 'ethiopian', 'am')->timeOfDay('17:30:00'))->toBe('11:30 ቀን')
        ->and(reader('ethiopian', '24h', 'am')->timeOfDay('17:30:00'))->toBe('17:30')
        ->and(reader('ethiopian', 'ethiopian', 'am')->timeOfDay('not a time'))->toBe('not a time');
});

test('a pay period is named by the month its middle falls in', function (string $start, string $end, string $calendar, string $locale, string $written) {
    expect(reader($calendar, '24h', $locale)->period($start, $end))->toBe($written);
})->with([
    'Ethiopian month, Ethiopian reader' => ['2026-10-11', '2026-11-09', 'ethiopian', 'am', 'ጥቅምት 2019'],
    'Gregorian month, Ethiopian reader' => ['2026-10-01', '2026-10-31', 'ethiopian', 'en', 'Tikimt 2019'],
    'Gregorian month, Gregorian reader' => ['2026-10-01', '2026-10-31', 'gregorian', 'en', 'October 2026'],
]);

test('auto is Ethiopian time only for Amharic on the Ethiopian calendar', function () {
    $tenant = new Tenant(['settings' => [], 'timezone' => 'Africa/Addis_Ababa']);

    expect(ReaderDates::forTenant($tenant, 'am')->clock)->toBe('ethiopian')
        ->and(ReaderDates::forTenant($tenant, 'en')->clock)->toBe('24h');
});

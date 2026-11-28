<?php

declare(strict_types=1);

use App\Services\Calendar\EthiopianCalendar;
use Carbon\Carbon;
use Tests\Accuracy\Calendar\CalendarFixtures;
use Tests\Accuracy\Oracle\Calendar\Odometer;

/*
 * Three implementations of the Ethiopian calendar are compared on every day
 * from 1900 to 2100: this class, the TypeScript module (its own test file) and
 * the Odometer, which counts days one at a time. The table they share is
 * committed at src/src/test/fixtures/ethiopian-calendar.json. To regenerate it
 * after a deliberate change to the Odometer or the seed:
 *
 *     CALENDAR_TABLE_WRITE=1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Calendar
 *     (cd ../src && npx prettier --write src/test/fixtures/ethiopian-calendar.json)
 *
 * Rules pinned: see pins.php.
 */

if (getenv('CALENDAR_TABLE_WRITE') === '1') {
    CalendarFixtures::writeCommitted();
}

it('has a committed table equal to what the Odometer counts from the seed', function (): void {
    expect(CalendarFixtures::committed())->toBe(json_decode(json_encode(CalendarFixtures::generated()), true));
});

it('covers every Ethiopian year with a day between 1900-01-01 and 2100-12-31', function (): void {
    $years = CalendarFixtures::committed()['years'];

    expect(array_keys($years))->toHaveCount(202)
        ->and((string) array_key_first($years))->toBe((string) CalendarFixtures::FIRST_YEAR)
        ->and((string) array_key_last($years))->toBe((string) CalendarFixtures::LAST_YEAR)
        ->and($years[CalendarFixtures::FIRST_YEAR]['months'][0] <= '1900-01-01')->toBeTrue()
        ->and($years[CalendarFixtures::FIRST_YEAR + 1]['months'][0] > '1900-01-01')->toBeTrue()
        ->and($years[CalendarFixtures::LAST_YEAR]['months'][0] <= '2100-12-31')->toBeTrue()
        ->and(CalendarFixtures::addDays($years[CalendarFixtures::LAST_YEAR]['months'][12], $years[CalendarFixtures::LAST_YEAR]['pagume_days'] - 1) >= '2100-12-31')->toBeTrue();
});

it('is consistent with itself: 12 months of 30 days, then Pagume, then the next Meskerem 1', function (): void {
    $years = CalendarFixtures::committed()['years'];
    $bad = [];

    foreach ($years as $year => $row) {
        $year = (int) $year;
        if (count($row['months']) !== 13) {
            $bad[] = "{$year}: ".count($row['months']).' month starts';

            continue;
        }
        $expectedPagume = $year % 4 === 3 ? 6 : 5;
        if ($row['pagume_days'] !== $expectedPagume) {
            $bad[] = "{$year}: Pagume {$row['pagume_days']} days, the rule says {$expectedPagume}";
        }
        for ($m = 1; $m <= 13; $m++) {
            $length = $m < 13 ? 30 : $row['pagume_days'];
            $next = $m < 13 ? $row['months'][$m] : ($years[$year + 1]['months'][0] ?? null);
            if ($next !== null && CalendarFixtures::addDays($row['months'][$m - 1], $length) !== $next) {
                $bad[] = "{$year}-{$m}: starts {$row['months'][$m - 1]}, +{$length} days is not {$next}";
            }
        }
    }

    expect($bad)->toBe([]);
});

it('agrees with every anchor, and the Odometer agrees with it', function (): void {
    $years = CalendarFixtures::committed()['years'];
    $odometer = Odometer::table(CalendarFixtures::seed(), CalendarFixtures::FIRST_YEAR, CalendarFixtures::LAST_YEAR);
    $cal = new EthiopianCalendar;
    $bad = [];

    foreach (CalendarFixtures::anchors() as $a) {
        [$y, $m, $d] = $a['ethiopian'];
        $fromTable = CalendarFixtures::addDays($years[$y]['months'][$m - 1], $d - 1);
        $fromOdometer = CalendarFixtures::addDays($odometer[$y]['months'][$m - 1], $d - 1);
        $fromPhp = $cal->ethiopianToGregorian($y, $m, $d)->toDateString();
        $back = $cal->gregorianToEthiopian(Carbon::parse($a['gregorian'], 'UTC'));

        foreach (['table' => $fromTable, 'odometer' => $fromOdometer, 'php' => $fromPhp] as $who => $got) {
            if ($got !== $a['gregorian']) {
                $bad[] = "{$a['id']} ({$a['source']}): {$who} says {$y}-{$m}-{$d} is {$got}, not {$a['gregorian']}";
            }
        }
        if ($back !== ['year' => $y, 'month' => $m, 'day' => $d]) {
            $bad[] = "{$a['id']}: php says {$a['gregorian']} is ".json_encode($back);
        }
    }

    expect($bad)->toBe([]);
});

it('has anchors with a source each, a unique id, and exactly one seed', function (): void {
    $anchors = CalendarFixtures::anchors();

    expect(array_column($anchors, 'id'))->toBe(array_values(array_unique(array_column($anchors, 'id'))))
        ->and(array_filter($anchors, static fn (array $a): bool => trim($a['source']) === ''))->toBe([])
        ->and(array_filter($anchors, static fn (array $a): bool => $a['seed']))->toHaveCount(1);
});

it('matches the table for every month start, and the day before it', function (): void {
    $years = CalendarFixtures::committed()['years'];
    $cal = new EthiopianCalendar;
    $bad = [];
    $compared = 0;

    foreach ($years as $year => $row) {
        $year = (int) $year;
        if ($cal->daysInPagume($year) !== $row['pagume_days'] || $cal->isLeapYear($year) !== ($row['pagume_days'] === 6)) {
            $bad[] = "{$year}: Pagume length";
        }
        foreach ($row['months'] as $i => $start) {
            $m = $i + 1;
            $compared++;
            $got = $cal->ethiopianToGregorian($year, $m, 1)->toDateString();
            if ($got !== $start) {
                $bad[] = "{$year}-{$m}-1: php {$got}, table {$start}";
            }
            if ($cal->gregorianToEthiopian(Carbon::parse($start, 'UTC')) !== ['year' => $year, 'month' => $m, 'day' => 1]) {
                $bad[] = "{$start}: php does not read it as {$year}-{$m}-1";
            }
            if ($year === CalendarFixtures::FIRST_YEAR && $m === 1) {
                continue;
            }
            $before = match (true) {
                $m > 1 => [$year, $m - 1, 30],
                default => [$year - 1, 13, $years[$year - 1]['pagume_days']],
            };
            $dayBefore = $cal->gregorianToEthiopian(Carbon::parse(CalendarFixtures::addDays($start, -1), 'UTC'));
            if ($dayBefore !== ['year' => $before[0], 'month' => $before[1], 'day' => $before[2]]) {
                $bad[] = CalendarFixtures::addDays($start, -1).': php reads '.json_encode($dayBefore).', expected '.implode('-', $before);
            }
        }
    }

    expect($bad)->toBe([])->and($compared)->toBe(202 * 13);
});

it('converts every day of every year both ways, and the next day is the next Ethiopian date', function (): void {
    $years = CalendarFixtures::committed()['years'];
    $cal = new EthiopianCalendar;
    $bad = [];
    $days = 0;
    $previous = null;

    foreach ($years as $year => $row) {
        $year = (int) $year;
        foreach ($row['months'] as $i => $start) {
            $m = $i + 1;
            $length = $m < 13 ? 30 : $row['pagume_days'];
            $date = $start;
            for ($d = 1; $d <= $length; $d++) {
                $days++;
                if ($previous !== null && $previous !== CalendarFixtures::addDays($date, -1)) {
                    $bad[] = "{$date}: does not follow {$previous}";
                }
                $eth = $cal->gregorianToEthiopian(Carbon::create((int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2), 0, 0, 0, 'UTC'));
                if ($eth !== ['year' => $year, 'month' => $m, 'day' => $d]) {
                    $bad[] = "{$date}: php reads {$eth['year']}-{$eth['month']}-{$eth['day']}, table {$year}-{$m}-{$d}";
                }
                $back = $cal->ethiopianToGregorian($year, $m, $d)->toDateString();
                if ($back !== $date) {
                    $bad[] = "{$year}-{$m}-{$d}: php gives {$back}, table {$date}";
                }
                $previous = $date;
                $date = CalendarFixtures::addDays($date, 1);
                if (count($bad) > 20) {
                    break 3;
                }
            }
        }
    }

    expect($bad)->toBe([])
        ->and($days)->toBeGreaterThanOrEqual(CalendarFixtures::daysBetween('1900-01-01', '2100-12-31') + 1);
});

it('counts the Pagume days of each year and names the year', function (): void {
    $years = CalendarFixtures::committed()['years'];
    $cal = new EthiopianCalendar;
    $bad = [];

    foreach ($years as $year => $row) {
        $year = (int) $year;
        $first = $row['months'][12];
        $last = CalendarFixtures::addDays($first, $row['pagume_days'] - 1);
        $got = $cal->pagumeDaysInRange(Carbon::parse($first, 'UTC'), Carbon::parse($last, 'UTC'));
        if ($got !== ['days' => $row['pagume_days'], 'ethiopian_year' => $year, 'pagume_length' => $row['pagume_days']]) {
            $bad[] = "{$year}: ".json_encode($got);
        }

        $around = $cal->pagumeDaysInRange(Carbon::parse(CalendarFixtures::addDays($first, -10), 'UTC'), Carbon::parse(CalendarFixtures::addDays($last, 10), 'UTC'));
        if ($around['days'] !== $row['pagume_days'] || $around['ethiopian_year'] !== $year) {
            $bad[] = "{$year} padded by ten days: ".json_encode($around);
        }
    }

    expect($bad)->toBe([]);
});

it('agrees with a published reference table of month starts', function (): void {
    $published = require __DIR__.'/published-month-starts.php';
    $years = CalendarFixtures::committed()['years'];
    $cal = new EthiopianCalendar;
    $bad = [];
    $compared = 0;

    // The page says its table holds from March 1900 to February 2100.
    for ($year = 1893; $year <= 2091; $year++) {
        for ($m = 1; $m <= 13; $m++) {
            [$gregorianMonth, $usual, $afterLeap] = $published[$m];
            $day = $year % 4 === 0 ? $afterLeap : $usual;
            $start = $years[$year]['months'][$m - 1];
            $compared++;
            if ((int) substr($start, 5, 2) !== $gregorianMonth || (int) substr($start, 8, 2) !== $day) {
                $bad[] = "{$year}-{$m}: the published table says month {$gregorianMonth} day {$day}, the table {$start}";
            }
            if ($cal->ethiopianToGregorian($year, $m, 1)->format('m-d') !== sprintf('%02d-%02d', $gregorianMonth, $day)) {
                $bad[] = "{$year}-{$m}: php gives ".$cal->ethiopianToGregorian($year, $m, 1)->toDateString();
            }
        }
    }

    expect($bad)->toBe([])->and($compared)->toBe(199 * 13);
});

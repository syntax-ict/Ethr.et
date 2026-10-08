<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Services\Calendar\EthiopianCalendar;
use App\Services\CurrentTenant;
use App\Services\Holiday\HolidayService;

// ── Orthodox computus (exact — these feasts are calculated, not sighted) ──

test('Ethiopian Orthodox Easter matches the published dates', function () {
    $calendar = new EthiopianCalendar;

    // Alexandrian computus, cross-checked against the published Ethiopian
    // Orthodox Tewahedo calendar.
    $expected = [
        2021 => '2021-05-02',
        2022 => '2022-04-24',
        2023 => '2023-04-16',
        2024 => '2024-05-05',
        2025 => '2025-04-20',
        2026 => '2026-04-12',
        2027 => '2027-05-02',
    ];

    foreach ($expected as $year => $date) {
        expect($calendar->orthodoxEaster($year)->format('Y-m-d'))->toBe($date);
    }
});

test('Fasika always falls on a Sunday and Siklet on the Friday before', function () {
    $calendar = new EthiopianCalendar;

    foreach (range(2020, 2040) as $year) {
        $easter = $calendar->orthodoxEaster($year);
        $goodFriday = $calendar->orthodoxGoodFriday($year);

        expect($easter->dayOfWeek)->toBe(0);            // Sunday
        expect($goodFriday->dayOfWeek)->toBe(5);        // Friday
        expect((int) abs($goodFriday->diffInDays($easter)))->toBe(2);
    }
});

// ── Tabular Hijri (approximate — flagged for HR to confirm) ──

test('Islamic feast dates land within a day of the observed dates', function () {
    $calendar = new EthiopianCalendar;

    // Observed in Ethiopia; the tabular calendar may differ by up to a day.
    $observed = [
        [2024, 10, 1, '2024-04-10'],   // Eid al-Fitr
        [2025, 10, 1, '2025-03-31'],
        [2024, 12, 10, '2024-06-16'],  // Eid al-Adha
        [2025, 12, 10, '2025-06-06'],
        [2024, 3, 12, '2024-09-15'],   // Mawlid
        [2025, 3, 12, '2025-09-04'],
    ];

    foreach ($observed as [$gregorianYear, $hijriMonth, $hijriDay, $observedDate]) {
        $years = $calendar->hijriYearsOverlapping($gregorianYear, $hijriMonth, $hijriDay);
        expect($years)->not->toBeEmpty();

        $computed = $calendar->hijriToGregorian($years[0], $hijriMonth, $hijriDay);

        expect(abs($computed->diffInDays($observedDate)))->toBeLessThanOrEqual(1);
    }
});

test('a Hijri year is resolved for every Gregorian year in a long run', function () {
    $calendar = new EthiopianCalendar;

    // The ~11-day annual drift must never leave a gap in the search window.
    foreach (range(2020, 2060) as $year) {
        expect($calendar->hijriYearsOverlapping($year, 10, 1))->not->toBeEmpty();
    }
});

// ── The 13 auto-detected holidays ──

test('auto-detect produces the 13 Ethiopian public holidays', function () {
    $tenant = createTenant();
    $service = app(HolidayService::class);

    $created = $service->autoDetect($tenant->id, 2026);

    expect($created)->toBe(13);
    expect(Holiday::where('tenant_id', $tenant->id)->count())->toBe(13);
});

test('the movable feasts are among the detected holidays', function () {
    $tenant = createTenant();
    app(HolidayService::class)->autoDetect($tenant->id, 2026);

    $names = Holiday::where('tenant_id', $tenant->id)->pluck('name')->all();

    expect($names)->toContain('Good Friday (Siklet)');
    expect($names)->toContain('Ethiopian Easter (Fasika)');
    expect($names)->toContain('Eid al-Fitr');
    expect($names)->toContain('Eid al-Adha (Arafa)');
    expect($names)->toContain('Prophet Muhammad Birthday (Mawlid)');
});

test('only the sighting-dependent holidays are flagged as estimated', function () {
    $tenant = createTenant();
    app(HolidayService::class)->autoDetect($tenant->id, 2026);

    $estimated = Holiday::where('tenant_id', $tenant->id)
        ->where('is_estimated', true)
        ->pluck('name')
        ->all();

    sort($estimated);

    expect($estimated)->toBe([
        'Eid al-Adha (Arafa)',
        'Eid al-Fitr',
        'Prophet Muhammad Birthday (Mawlid)',
    ]);

    // Fasika is computed, so it must not be flagged.
    expect(
        Holiday::where('tenant_id', $tenant->id)
            ->where('name', 'Ethiopian Easter (Fasika)')
            ->value('is_estimated')
    )->toBeFalsy();
});

test('auto-detect stays idempotent with the movable feasts', function () {
    $tenant = createTenant();
    $service = app(HolidayService::class);

    $first = $service->autoDetect($tenant->id, 2026);
    $second = $service->autoDetect($tenant->id, 2026);

    expect($first)->toBe(13);
    expect($second)->toBe(0);
    expect(Holiday::where('tenant_id', $tenant->id)->count())->toBe(13);
});

test('a run covers exactly the Gregorian year it names', function () {
    $service = app(HolidayService::class);

    // Until 2026-10-07 a run covered the Ethiopian year beginning in September,
    // so Genna, Timkat and Adwa spilled into the next Gregorian year while
    // Labour Day and Easter stayed in this one. No run a new tenant got ever
    // produced this year's Genna, Timkat or Adwa (audit N57).
    foreach ([2025, 2026, 2027] as $year) {
        foreach ($service->getEthiopianHolidays($year) as $holiday) {
            expect((int) substr((string) $holiday['date'], 0, 4))->toBe($year);
        }
    }
});

test('a 2026 run includes January to March 2026, which it used to skip', function () {
    $dates = collect(app(HolidayService::class)->getEthiopianHolidays(2026))
        ->pluck('date', 'name');

    expect($dates['Ethiopian Christmas (Genna)'])->toBe('2026-01-07')
        ->and($dates['Timkat (Epiphany)'])->toBe('2026-01-19')
        ->and($dates['Adwa Victory Day'])->toBe('2026-03-02')
        ->and($dates['Ethiopian New Year (Enkutatash)'])->toBe('2026-09-11')
        ->and($dates['Meskel (Finding of the True Cross)'])->toBe('2026-09-27');
});

test('auto-detect does not duplicate a holiday the tenant already recorded', function () {
    $tenant = createTenant();

    // Same day as Genna, entered by hand under a shorter name.
    Holiday::create([
        'tenant_id' => $tenant->id,
        'name' => 'Ethiopian Christmas',
        'date' => '2026-01-07',
        'is_active' => true,
    ]);

    app(HolidayService::class)->autoDetect($tenant->id, 2026);

    $onGenna = Holiday::where('tenant_id', $tenant->id)
        ->whereDate('date', '2026-01-07')
        ->count();

    expect($onGenna)->toBe(1);
    expect(Holiday::where('tenant_id', $tenant->id)->count())->toBe(13);
});

test('auto-detect leaves branch-specific holidays alone', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    // A branch-only closure that happens to fall on Labour Day.
    Holiday::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'Branch Stocktake',
        'date' => '2026-05-01',
        'is_active' => true,
    ]);

    $created = app(HolidayService::class)->autoDetect($tenant->id, 2026);

    // The branch row does not mask the tenant-wide Labour Day.
    expect($created)->toBe(13);
    expect(
        Holiday::where('tenant_id', $tenant->id)->whereDate('date', '2026-05-01')->count()
    )->toBe(2);
});

test('one run gives its calendar year all 13 holidays, with nothing from the next', function () {
    $tenant = createTenant();

    // It took two runs before (2025's supplied 2026's Genna, Timkat and Adwa),
    // and a tenant created in 2026 never had the 2025 one (audit N57).
    app(HolidayService::class)->autoDetect($tenant->id, 2026);

    expect(Holiday::where('tenant_id', $tenant->id)->whereYear('date', 2026)->count())->toBe(13)
        ->and(Holiday::where('tenant_id', $tenant->id)->whereYear('date', 2027)->count())->toBe(0);
});

// ── N58: "recurring every year" ─────────────────────────────────────────────

function rollForwardFor(Tenant $tenant, int $toYear): int
{
    app(CurrentTenant::class)->set($tenant);

    return app(HolidayService::class)->rollForward($tenant->id, $toYear);
}

test('a company holiday marked recurring is created for the next year', function () {
    $tenant = createTenant();
    Holiday::create([
        'tenant_id' => $tenant->id, 'name' => 'Company Founding Day',
        'date' => '2026-06-15', 'recurring' => true, 'is_active' => true,
    ]);

    rollForwardFor($tenant, 2027);

    expect(Holiday::where('tenant_id', $tenant->id)->where('name', 'Company Founding Day')
        ->whereDate('date', '2027-06-15')->exists())->toBeTrue();
});

test('a one-off company holiday is not repeated', function () {
    $tenant = createTenant();
    Holiday::create([
        'tenant_id' => $tenant->id, 'name' => 'Office move',
        'date' => '2026-06-15', 'recurring' => false, 'is_active' => true,
    ]);

    rollForwardFor($tenant, 2027);

    expect(Holiday::where('tenant_id', $tenant->id)->where('name', 'Office move')->count())->toBe(1);
});

test('rolling forward creates next year\'s statutory holidays, computed for that year', function () {
    $tenant = createTenant();

    rollForwardFor($tenant, 2027);

    // Easter moves: copying 2026's date by month and day would be wrong.
    expect(Holiday::where('tenant_id', $tenant->id)->where('name', 'Ethiopian Easter (Fasika)')
        ->whereDate('date', '2027-05-02')->exists())->toBeTrue();
});

test('rolling forward never copies an Ethiopian-calendar or estimated holiday by date', function () {
    $tenant = createTenant();
    app(HolidayService::class)->autoDetect($tenant->id, 2026);

    rollForwardFor($tenant, 2027);

    // 2026's Easter (12 April) and Eid al-Fitr must not reappear on the same
    // day in 2027; their 2027 dates come from autoDetect.
    expect(Holiday::where('tenant_id', $tenant->id)->whereDate('date', '2027-04-12')->exists())->toBeFalse();
});

test('rolling forward is idempotent', function () {
    $tenant = createTenant();
    Holiday::create([
        'tenant_id' => $tenant->id, 'name' => 'Company Founding Day',
        'date' => '2026-06-15', 'recurring' => true, 'is_active' => true,
    ]);

    $first = rollForwardFor($tenant, 2027);
    $second = rollForwardFor($tenant, 2027);

    expect($first)->toBe(14)->and($second)->toBe(0);
});

test('rolling forward refuses to run for a tenant that is not current', function () {
    $tenant = createTenant();
    app(CurrentTenant::class)->forget();

    app(HolidayService::class)->rollForward($tenant->id, 2027);
})->throws(LogicException::class);

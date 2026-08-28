<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Holiday;
use App\Services\Calendar\EthiopianCalendar;
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

test('a run covers the Ethiopian year beginning in September of the given year', function () {
    $service = app(HolidayService::class);

    // Ethiopian-calendar feasts after Meskerem 1 land in the next Gregorian
    // year — Genna, Timkat and Adwa always spill over. The run is therefore
    // bounded by [year, year + 1], not by the single calendar year.
    foreach ([2025, 2026, 2027] as $year) {
        foreach ($service->getEthiopianHolidays($year) as $holiday) {
            expect((int) substr((string) $holiday['date'], 0, 4))
                ->toBeGreaterThanOrEqual($year)
                ->toBeLessThanOrEqual($year + 1);
        }
    }
});

test('auto-detect does not duplicate a holiday the tenant already recorded', function () {
    $tenant = createTenant();

    // Same day as Genna, entered by hand under a shorter name.
    Holiday::create([
        'tenant_id' => $tenant->id,
        'name' => 'Ethiopian Christmas',
        'date' => '2027-01-07',
        'is_active' => true,
    ]);

    app(HolidayService::class)->autoDetect($tenant->id, 2026);

    $onGenna = Holiday::where('tenant_id', $tenant->id)
        ->whereDate('date', '2027-01-07')
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

test('seeding consecutive years gives a calendar year exactly 13 holidays', function () {
    $tenant = createTenant();
    $service = app(HolidayService::class);

    // The spill-over from the previous run is what completes a calendar year:
    // 2025's run supplies Genna, Timkat and Adwa for 2026.
    $service->autoDetect($tenant->id, 2025);
    $service->autoDetect($tenant->id, 2026);

    $inCalendar2026 = Holiday::where('tenant_id', $tenant->id)
        ->whereYear('date', 2026)
        ->count();

    expect($inCalendar2026)->toBe(13);
});

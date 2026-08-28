<?php

declare(strict_types=1);

use App\Services\Analytics\TrendForecaster;

it('projects a perfectly linear series forward', function () {
    $projected = TrendForecaster::project(
        [10, 20, 30],
        ['2026-01', '2026-02', '2026-03'],
        2,
    );

    expect($projected)->toBe([
        ['label' => '2026-04', 'value' => 40.0],
        ['label' => '2026-05', 'value' => 50.0],
    ]);
});

it('rolls the year over when projecting past December', function () {
    $projected = TrendForecaster::project(
        [5, 10],
        ['2026-11', '2026-12'],
        2,
    );

    expect(array_column($projected, 'label'))->toBe(['2027-01', '2027-02']);
});

it('returns nothing for fewer than two historical points', function () {
    expect(TrendForecaster::project([42], ['2026-01'], 3))->toBe([]);
    expect(TrendForecaster::project([], [], 3))->toBe([]);
});

it('never projects a negative value for a steeply declining series', function () {
    $projected = TrendForecaster::project(
        [100, 50, 0],
        ['2026-01', '2026-02', '2026-03'],
        3,
    );

    foreach ($projected as $point) {
        expect($point['value'])->toBeGreaterThanOrEqual(0);
    }
});

it('falls back to a numbered suffix for non year-month labels', function () {
    $projected = TrendForecaster::project(
        [1000, 2000],
        ['Period A', 'Period B'],
        1,
    );

    expect($projected[0]['label'])->toBe('Period B +1');
});

it('handles a flat (zero-slope) series without error', function () {
    $projected = TrendForecaster::project(
        [10, 10, 10],
        ['2026-01', '2026-02', '2026-03'],
        2,
    );

    expect($projected)->toBe([
        ['label' => '2026-04', 'value' => 10.0],
        ['label' => '2026-05', 'value' => 10.0],
    ]);
});

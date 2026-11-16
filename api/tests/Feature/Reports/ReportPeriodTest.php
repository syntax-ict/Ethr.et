<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\SavedReport;
use App\Services\Report\ReportEngine;
use App\Services\Report\ReportPeriod;
use Carbon\Carbon;

/*
 * Rolling periods, resolved on the day a report runs. The month periods are
 * the Analytics page's (analytics/period.ts), in the organisation's calendar.
 */

function periodOf(string $key, mixed $tenant, string $today): array
{
    [$from, $to] = ReportPeriod::resolve($key, $tenant, Carbon::parse($today));

    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

test('the periods follow the Gregorian calendar for an organisation on it', function (string $key, array $expected) {
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);

    expect(periodOf($key, $tenant, '2026-11-15'))->toBe($expected);
})->with([
    'yesterday' => ['yesterday', ['2026-11-14', '2026-11-14']],
    'last 7 days' => ['last_7_days', ['2026-11-08', '2026-11-14']],
    'this month' => ['this_month', ['2026-11-01', '2026-11-30']],
    'last month' => ['last_month', ['2026-10-01', '2026-10-31']],
    'last 3 months' => ['last_3_months', ['2026-09-01', '2026-11-30']],
    'last 12 months' => ['last_12_months', ['2025-12-01', '2026-11-30']],
    'this fiscal year, from Meskerem' => ['this_fiscal_year', ['2026-09-11', '2026-11-15']],
    'last fiscal year, from Meskerem' => ['last_fiscal_year', ['2025-09-11', '2026-09-10']],
]);

test('a Gregorian organisation in January counts last month back into the old year', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);

    expect(periodOf('last_month', $tenant, '2027-01-10'))->toBe(['2026-12-01', '2026-12-31'])
        ->and(periodOf('last_3_months', $tenant, '2027-01-10'))->toBe(['2026-11-01', '2027-01-31']);
});

test('the month periods are months of the Ethiopian calendar for an organisation on it', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'ethiopian']]);

    // 15 November 2026 is Hidar 6, 2019: Hidar runs 10 November to 9 December.
    expect(periodOf('this_month', $tenant, '2026-11-15'))->toBe(['2026-11-10', '2026-12-09'])
        ->and(periodOf('last_month', $tenant, '2026-11-15'))->toBe(['2026-10-11', '2026-11-09'])
        ->and(periodOf('last_3_months', $tenant, '2026-11-15'))->toBe(['2026-09-11', '2026-12-09']);
});

test('a day inside Pagume belongs to the thirteenth month, and last month is Nehase', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'ethiopian']]);

    // 8 September 2027 is Pagume 3, 2019, a six-day Pagume.
    expect(periodOf('this_month', $tenant, '2027-09-08'))->toBe(['2027-09-06', '2027-09-11'])
        ->and(periodOf('last_month', $tenant, '2027-09-08'))->toBe(['2027-08-07', '2027-09-05'])
        ->and(periodOf('last_3_months', $tenant, '2027-09-08'))->toBe(['2027-07-08', '2027-09-11'])
        ->and(periodOf('last_12_months', $tenant, '2027-09-08'))->toBe(['2026-10-11', '2027-09-11']);
});

test('the day after the Ethiopian new year starts a month and a year', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'ethiopian']]);

    // 12 September 2027 is Meskerem 1, 2020.
    expect(periodOf('this_month', $tenant, '2027-09-12'))->toBe(['2027-09-12', '2027-10-11'])
        ->and(periodOf('last_month', $tenant, '2027-09-12'))->toBe(['2027-09-06', '2027-09-11'])
        ->and(periodOf('last_12_months', $tenant, '2027-09-12'))->toBe(['2026-11-10', '2027-10-11']);
});

test('the fiscal year starts in Meskerem unless the organisation says otherwise', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'ethiopian']]);

    expect(periodOf('this_fiscal_year', $tenant, '2027-09-08'))->toBe(['2026-09-11', '2027-09-08'])
        ->and(periodOf('last_fiscal_year', $tenant, '2027-09-08'))->toBe(['2025-09-11', '2026-09-10'])
        ->and(periodOf('this_fiscal_year', $tenant, '2027-09-12'))->toBe(['2027-09-12', '2027-09-12'])
        ->and(periodOf('last_fiscal_year', $tenant, '2027-09-12'))->toBe(['2026-09-11', '2027-09-11']);
});

test('a fiscal year that starts in Hamle began in the year before the new year', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'ethiopian', 'fiscal_year_start_month' => 11]]);

    // Pagume 3, 2019 is after Hamle 1, 2019 (8 July 2027).
    expect(periodOf('this_fiscal_year', $tenant, '2027-09-08'))->toBe(['2027-07-08', '2027-09-08'])
        ->and(periodOf('last_fiscal_year', $tenant, '2027-09-08'))->toBe(['2026-07-08', '2027-07-07'])
        // Meskerem 1, 2020 is before Hamle 1, 2020: still the year that began in Hamle 2019.
        ->and(periodOf('this_fiscal_year', $tenant, '2027-09-12'))->toBe(['2027-07-08', '2027-09-12'])
        ->and(periodOf('last_fiscal_year', $tenant, '2027-09-12'))->toBe(['2026-07-08', '2027-07-07']);
});

test('a saved report with a period covers a different month on a different day', function () {
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    foreach (['2026-09-15', '2026-10-15'] as $day) {
        AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => $day]);
    }
    $saved = SavedReport::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'config' => ['source' => 'attendance', 'columns' => ['date'], 'filters' => ['period' => 'last_month', 'from' => '2001-01-01']],
    ]);

    $run = function (string $today) use ($tenant, $user, $saved): array {
        test()->travelTo(Carbon::parse($today, 'Africa/Addis_Ababa'));

        return app(ReportEngine::class)->generate($tenant->id, $saved->config, $user);
    };

    $october = $run('2026-11-10 10:00');
    $september = $run('2026-10-10 10:00');

    expect(array_column($october['data'], 'date'))->toBe(['2026-10-15'])
        ->and($october['period'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->and(array_column($september['data'], 'date'))->toBe(['2026-09-15'])
        ->and($september['period'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-30']);
});

test('the sources that take dates publish the period filter', function () {
    $sources = app(ReportEngine::class)->sources();

    foreach (['attendance', 'timesheet', 'absences', 'payroll'] as $source) {
        expect($sources[$source]['filters'])->toContain('period');
    }
    expect($sources['employees']['filters'])->not->toContain('period')
        ->and($sources['leave']['filters'])->not->toContain('period');
});

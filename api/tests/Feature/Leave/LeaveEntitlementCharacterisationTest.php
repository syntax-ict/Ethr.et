<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\SickLeaveTiers;
use App\Services\Onboarding\LeaveTypeCatalog;
use Carbon\Carbon;

/*
 * Characterisation (2026-11-09): the leave entitlements the code grants
 * before the statutory floors move from constants into statutory_parameters
 * rows. Every figure here was read from the code's own output, not from the
 * law; the move must leave each one exactly where it is. Labour Proclamation
 * 1156/2019: Art. 77(1) annual and its seniority step, Art. 85 sick-leave
 * tiers, Art. 88 maternity.
 */

test('the catalogue grants the statutory leave defaults', function () {
    $catalog = app(LeaveTypeCatalog::class);

    $annual = $catalog->resolve('annual');
    $sick = $catalog->resolve('sick');
    $maternity = $catalog->resolve('maternity');
    $paternity = $catalog->resolve('paternity');

    expect($annual['default_days'])->toBe(16)
        ->and($annual['seniority_every_years'])->toBe(2)
        ->and($annual['seniority_extra_days'])->toBe(1)
        ->and($sick['default_days'])->toBe(30)
        ->and($sick['follows_art85'])->toBeTrue()
        ->and($maternity['default_days'])->toBe(120)
        ->and($maternity['counts_calendar_days'])->toBeTrue()
        ->and($paternity['default_days'])->toBe(3);
});

test('annual entitlement adds one day for every two completed years', function (string $hired, string $asOf, float $expected) {
    $tenant = createTenant();
    $annual = app(LeaveTypeCatalog::class)->resolve('annual');
    $type = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'default_days' => $annual['default_days'],
        'seniority_every_years' => $annual['seniority_every_years'],
        'seniority_extra_days' => $annual['seniority_extra_days'],
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => $hired]);

    expect(app(LeaveBalanceService::class)->yearlyEntitlement($employee, $type, Carbon::parse($asOf)))->toBe($expected);
})->with([
    'first year' => ['2026-01-01', '2026-06-01', 16.0],
    'one year done' => ['2025-01-01', '2026-06-01', 16.0],
    'two years done' => ['2024-01-01', '2026-06-01', 17.0],
    'six years done' => ['2020-01-01', '2026-06-01', 19.0],
    'seven years done' => ['2019-01-01', '2026-06-01', 19.0],
]);

test('sick-leave tiers are one month full, two half, three unpaid within twelve months', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2020-01-01']);

    $dates = [];
    $day = Carbon::parse('2026-01-01');
    for ($i = 0; $i < 140; $i++) {
        $dates[] = $day->copy()->addDays($i)->format('Y-m-d');
    }

    $tiers = array_values(app(SickLeaveTiers::class)->tiersOf($employee, 22, $dates));

    expect($tiers[21])->toBe(SickLeaveTiers::FULL)
        ->and($tiers[22])->toBe(SickLeaveTiers::HALF)
        ->and($tiers[65])->toBe(SickLeaveTiers::HALF)
        ->and($tiers[66])->toBe(SickLeaveTiers::UNPAID)
        ->and($tiers[131])->toBe(SickLeaveTiers::UNPAID)
        ->and($tiers[132])->toBe(SickLeaveTiers::BEYOND);
});

test('a sick-leave period opens a new twelve months after the last one closes', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2020-01-01']);

    $tiers = app(SickLeaveTiers::class)->tiersOf($employee, 1, ['2025-01-01', '2025-06-01', '2026-01-01']);

    expect($tiers['2025-01-01'])->toBe(SickLeaveTiers::FULL)
        ->and($tiers['2025-06-01'])->toBe(SickLeaveTiers::HALF)
        ->and($tiers['2026-01-01'])->toBe(SickLeaveTiers::FULL);
});

test('a person with no sick leave may take six months of it', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2020-01-01']);

    $standing = app(SickLeaveTiers::class)->standing($employee, 22, Carbon::parse('2026-06-01'));

    expect($standing['days_allowed'])->toBe(132)
        ->and($standing['days_taken'])->toBe(0)
        ->and($standing['next_tier'])->toBe(SickLeaveTiers::FULL);
});

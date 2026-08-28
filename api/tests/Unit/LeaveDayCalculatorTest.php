<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Services\Leave\LeaveDayCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('counts only weekdays', function () {
    $calc = new LeaveDayCalculator;

    // Mon 2025-01-06 to Fri 2025-01-10 = 5 working days
    $days = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-10'),
        tenantId: 1,
    );

    expect($days)->toBe(5.0);
});

test('excludes weekends', function () {
    $calc = new LeaveDayCalculator;

    // Mon 2025-01-06 to Sun 2025-01-12 = 5 working days (Mon-Fri)
    $days = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-12'),
        tenantId: 1,
    );

    expect($days)->toBe(5.0);
});

test('two full weeks is 10 working days', function () {
    $calc = new LeaveDayCalculator;

    // Mon 2025-01-06 to Fri 2025-01-17 = 10 working days
    $days = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-17'),
        tenantId: 1,
    );

    expect($days)->toBe(10.0);
});

test('excludes tenant holidays', function () {
    $tenant = Tenant::factory()->create();

    Holiday::unguard();
    Holiday::create([
        'tenant_id' => $tenant->id,
        'name' => 'Meskel',
        'date' => '2025-01-08',
        'is_active' => true,
        'branch_id' => null,
    ]);
    Holiday::reguard();

    $calc = new LeaveDayCalculator;

    $days = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-10'),
        tenantId: $tenant->id,
    );

    expect($days)->toBe(4.0);
});

test('excludes branch-specific holidays', function () {
    $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    Holiday::unguard();
    Holiday::create([
        'tenant_id' => $tenant->id,
        'name' => 'Branch Holiday',
        'date' => '2025-01-07',
        'is_active' => true,
        'branch_id' => $branch->id,
    ]);
    Holiday::reguard();

    $calc = new LeaveDayCalculator;

    $withBranch = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-10'),
        tenantId: $tenant->id,
        branchId: $branch->id,
    );

    $withoutBranch = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-10'),
        tenantId: $tenant->id,
    );

    expect($withBranch)->toBe(4.0);
    expect($withoutBranch)->toBe(5.0);
});

test('single day leave returns one day', function () {
    $calc = new LeaveDayCalculator;

    $days = $calc->calculateDays(
        Carbon::parse('2025-01-06'), // Monday
        Carbon::parse('2025-01-06'),
        tenantId: 1,
    );

    expect($days)->toBe(1.0);
});

test('weekend-only range returns zero', function () {
    $calc = new LeaveDayCalculator;

    // Sat to Sun
    $days = $calc->calculateDays(
        Carbon::parse('2025-01-11'),
        Carbon::parse('2025-01-12'),
        tenantId: 1,
    );

    expect($days)->toBe(0.0);
});

test('ignores inactive holidays', function () {
    $tenant = Tenant::factory()->create();

    Holiday::unguard();
    Holiday::create([
        'tenant_id' => $tenant->id,
        'name' => 'Cancelled Holiday',
        'date' => '2025-01-08',
        'is_active' => false,
        'branch_id' => null,
    ]);
    Holiday::reguard();

    $calc = new LeaveDayCalculator;

    $days = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-10'),
        tenantId: $tenant->id,
    );

    expect($days)->toBe(5.0);
});

test('does not count holidays from other tenants', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    Holiday::unguard();
    Holiday::create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Other Tenant Holiday',
        'date' => '2025-01-08',
        'is_active' => true,
        'branch_id' => null,
    ]);
    Holiday::reguard();

    $calc = new LeaveDayCalculator;

    $days = $calc->calculateDays(
        Carbon::parse('2025-01-06'),
        Carbon::parse('2025-01-10'),
        tenantId: $tenant->id,
    );

    expect($days)->toBe(5.0);
});

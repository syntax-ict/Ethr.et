<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Holiday;
use App\Services\Holiday\HolidayService;
use Carbon\Carbon;

// ── HolidayService ──

test('holiday service detects holidays for tenant', function () {
    $tenant = createTenant();

    Holiday::factory()->count(3)->sequence(
        ['date' => '2026-01-01'],
        ['date' => '2026-09-11'],
        ['date' => '2026-09-27'],
    )->create(['tenant_id' => $tenant->id, 'is_active' => true]);

    $service = app(HolidayService::class);
    $holidays = $service->getHolidays($tenant->id, 2026);

    expect($holidays)->toHaveCount(3);
});

test('holiday service checks if date is holiday', function () {
    $tenant = createTenant();

    Holiday::factory()->create([
        'tenant_id' => $tenant->id,
        'date' => '2026-09-11',
        'is_active' => true,
    ]);

    $service = app(HolidayService::class);

    expect($service->isHoliday($tenant->id, Carbon::parse('2026-09-11')))->toBeTrue();
    expect($service->isHoliday($tenant->id, Carbon::parse('2026-09-12')))->toBeFalse();
});

test('branch-specific holiday only applies to that branch', function () {
    $tenant = createTenant();
    $branch = \App\Models\Branch::factory()->create(['tenant_id' => $tenant->id]);
    $otherBranch = \App\Models\Branch::factory()->create(['tenant_id' => $tenant->id]);

    Holiday::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'date' => '2026-03-15',
        'is_active' => true,
    ]);

    $service = app(HolidayService::class);

    expect($service->isHoliday($tenant->id, Carbon::parse('2026-03-15'), $branch->id))->toBeTrue();
    expect($service->isHoliday($tenant->id, Carbon::parse('2026-03-15'), $otherBranch->id))->toBeFalse();
});

// ── Ethiopian Holiday Auto-Detection ──

test('holiday service generates ethiopian holidays', function () {
    $service = app(HolidayService::class);
    $holidays = $service->getEthiopianHolidays(2026);

    expect($holidays)->toBeArray();
    expect(count($holidays))->toBeGreaterThanOrEqual(8);

    $names = array_column($holidays, 'name');
    expect($names)->toContain('Ethiopian New Year (Enkutatash)');
    expect($names)->toContain('Meskel (Finding of the True Cross)');
    expect($names)->toContain('Timkat (Epiphany)');
    expect($names)->toContain('Ethiopian Christmas (Genna)');
    expect($names)->toContain('Adwa Victory Day');
    expect($names)->toContain('Labour Day');
});

test('auto-detect creates holidays for tenant', function () {
    $tenant = createTenant();
    $service = app(HolidayService::class);

    $created = $service->autoDetect($tenant->id, 2026);

    expect($created)->toBeGreaterThanOrEqual(8);

    $holidays = Holiday::where('tenant_id', $tenant->id)->count();
    expect($holidays)->toBe($created);
});

test('auto-detect is idempotent', function () {
    $tenant = createTenant();
    $service = app(HolidayService::class);

    $first = $service->autoDetect($tenant->id, 2026);
    $second = $service->autoDetect($tenant->id, 2026);

    expect($second)->toBe(0);

    $total = Holiday::where('tenant_id', $tenant->id)->count();
    expect($total)->toBe($first);
});

// ── Auto-Detect Endpoint ──

test('hr admin can auto-detect holidays via api', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays/auto-detect", [
        'year' => 2026,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['year', 'created']);
    expect($response->json('created'))->toBeGreaterThanOrEqual(8);
});

test('employee cannot auto-detect holidays', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays/auto-detect", [
        'year' => 2026,
    ])->assertForbidden();
});

// ── Leave Day Calculation Integration ──

test('leave day calculation skips auto-detected holidays', function () {
    $tenant = createTenant();
    $service = app(HolidayService::class);
    $service->autoDetect($tenant->id, 2026);

    $calculator = app(\App\Services\Leave\LeaveDayCalculator::class);

    $labourDay = Carbon::create(2026, 5, 1);

    if ($labourDay->isWeekend()) {
        $this->markTestSkipped('Labour Day falls on weekend in 2026');
    }

    $start = Carbon::create(2026, 4, 30);
    while ($start->isWeekend()) {
        $start->subDay();
    }
    $end = Carbon::create(2026, 5, 2);
    while ($end->isWeekend()) {
        $end->addDay();
    }

    $daysWithHoliday = $calculator->calculateDays($start, $end, $tenant->id);

    Holiday::where('tenant_id', $tenant->id)->delete();
    $daysWithoutHoliday = $calculator->calculateDays($start, $end, $tenant->id);

    expect($daysWithHoliday)->toBeLessThan($daysWithoutHoliday);
});

// ── Auto-Detect Audit Log ──

test('auto-detect is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays/auto-detect", [
        'year' => 2026,
    ])->assertOk();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'holiday.auto_detected',
    ]);
});

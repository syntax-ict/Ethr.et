<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\ExpectedDay;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\ShiftRotation;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceBaseline;
use App\Services\Attendance\ExpectedDays;
use App\Services\Attendance\ShiftMatcher;
use App\Services\CurrentTenant;
use App\Services\Onboarding\OrganizationProvisioner;
use App\Services\Onboarding\WorkforceProfileCatalog;
use Carbon\Carbon;

/**
 * Every organisation gets a working attendance setup without opening a
 * settings page: its industry's usual schedule, working week and holidays.
 * Until 2026-10-24 sign-up created none, so every punch was PENDING and every
 * weekday without one an absence.
 */
afterEach(fn () => Carbon::setTestNow());

function baselineRegister(string $subdomain, string $type): Tenant
{
    test()->postJson('/api/v1/auth/register', [
        'organization_name' => 'Baseline '.$subdomain,
        'subdomain' => $subdomain,
        'admin_name' => 'Baseline Admin',
        'admin_email' => "admin@{$subdomain}.test",
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
        'organization_type' => $type,
    ])->assertCreated();

    $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();
    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

test('a new organisation can rate its first punch without any setup', function () {
    // Monday 12 October 2026, 08:20 in Addis Ababa.
    Carbon::setTestNow(Carbon::parse('2026-10-12 08:20', 'Africa/Addis_Ababa')->utc());
    $tenant = baselineRegister('baseline-office', 'general');

    $default = Shift::query()->where('is_default', true)->sole();
    expect($default->name)->toBe('Regular')
        ->and(substr((string) $default->start_time, 0, 5))->toBe('08:30')
        ->and(AttendanceSetting::query()->exists())->toBeTrue()
        ->and(Holiday::query()->exists())->toBeTrue()
        ->and($tenant->fresh()->settings['working_days'])->toBe([1, 2, 3, 4, 5]);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", ['idempotency_key' => 'bl-1'])
        ->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->sole()->status)->toBe(AttendanceStatus::PRESENT);
});

test('a bank works the six-day week, Saturday morning included, as its default schedule', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00', 'Africa/Addis_Ababa')->utc());
    $tenant = baselineRegister('baseline-bank', 'bank');

    $rotation = ShiftRotation::query()->where('is_default', true)->sole();
    // Anchored on the Monday of the week it was made the default.
    expect($rotation->anchor_date->format('Y-m-d'))->toBe('2026-10-12');

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $matcher = app(ShiftMatcher::class);

    expect($matcher->match($employee, Carbon::parse('2026-10-15 08:00', 'Africa/Addis_Ababa'))?->name)->toBe('Weekday')
        ->and($matcher->match($employee, Carbon::parse('2026-10-17 08:00', 'Africa/Addis_Ababa'))?->name)->toBe('Saturday')
        ->and($matcher->match($employee, Carbon::parse('2026-10-18 08:00', 'Africa/Addis_Ababa')))->toBeNull();

    $sunday = app(ExpectedDays::class)->forEmployee($employee, Carbon::parse('2026-10-18'), Carbon::parse('2026-10-18'));
    $saturday = app(ExpectedDays::class)->forEmployee($employee, Carbon::parse('2026-10-17'), Carbon::parse('2026-10-17'));
    expect($sunday['2026-10-18'])->toBe(ExpectedDay::REST_DAY)
        ->and($saturday['2026-10-17'])->toBe(ExpectedDay::WORKDAY);
});

test('a hospital gets office hours as the default and its crew rotation ready to assign', function () {
    $tenant = baselineRegister('baseline-hospital', 'hospital');

    expect(Shift::query()->where('is_default', true)->sole()->name)->toBe('Regular')
        ->and(Shift::query()->pluck('name')->all())->toContain('Morning', 'Afternoon', 'Night')
        ->and(ShiftRotation::query()->where('is_default', true)->exists())->toBeFalse()
        ->and(ShiftRotation::query()->exists())->toBeTrue()
        // Nothing that needs consent or changes pay is switched on unasked.
        ->and(AttendanceSetting::query()->sole()->auto_checkout_enabled)->toBeFalse()
        ->and($tenant->fresh()->settings['working_days'])->toBe([1, 2, 3, 4, 5]);
});

test('applying a profile in the wizard replaces an unused baseline', function () {
    $tenant = baselineRegister('baseline-replaced', 'general');
    $profile = app(WorkforceProfileCatalog::class)->find('six_day_week');

    app(OrganizationProvisioner::class)->apply($tenant->fresh(), [
        'shifts' => $profile['shifts'],
        'rotations' => $profile['rotations'],
    ]);

    expect(Shift::query()->where('is_default', true)->sole()->name)->toBe('Weekday')
        ->and(Shift::query()->where('name', 'Regular')->exists())->toBeFalse()
        ->and($tenant->fresh()->settings)->not->toHaveKey('attendance_baseline');
});

test('a baseline someone has already punched against stays', function () {
    $tenant = baselineRegister('baseline-kept', 'general');
    $regular = Shift::query()->where('name', 'Regular')->sole();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'shift_id' => $regular->id]);
    $profile = app(WorkforceProfileCatalog::class)->find('six_day_week');

    app(OrganizationProvisioner::class)->apply($tenant->fresh(), ['shifts' => $profile['shifts']]);

    expect($regular->fresh()->is_default)->toBeTrue()
        ->and($regular->fresh()->trashed())->toBeFalse();
});

test('the hourly sweep provisions organisations with nothing and heals a lost default, once', function () {
    $empty = createTenant(['type' => 'general']);
    $lost = createTenant();
    $orphan = Shift::factory()->create(['tenant_id' => $lost->id, 'is_active' => true, 'is_default' => false]);

    test()->artisan('attendance:baseline')
        ->expectsOutputToContain('1 provisioned, 1 healed')
        ->assertSuccessful();

    app(CurrentTenant::class)->set($empty);
    expect(Shift::query()->where('is_default', true)->exists())->toBeTrue();
    expect($orphan->fresh()->is_default)->toBeTrue();

    test()->artisan('attendance:baseline')
        ->expectsOutputToContain('0 provisioned, 0 healed')
        ->assertSuccessful();
});

test('the industry chosen in the wizard outranks the sign-up type', function () {
    $tenant = createTenant(['type' => 'general', 'settings' => ['industry' => 'hospital']]);

    expect(app(AttendanceBaseline::class)->profileKeyFor($tenant))->toBe('continuous_24_7');
});

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\TenantCreated;
use App\Jobs\CarryForwardLeaveBalancesJob;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveYear;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/*
 * The leave year follows the organisation: Gregorian (1 January) or Ethiopian
 * (Meskerem 1). Every balance was keyed on the Gregorian `->year`, so leave
 * reset on 1 January for an organisation keeping its books Meskerem to Pagume.
 *
 * Ethiopian 2019 runs from 2026-09-11 to 2027-09-11; it ends with a six-day
 * Pagume (2019 % 4 === 3).
 */

afterEach(fn () => Carbon::setTestNow());

function leaveYearTenant(string $kind): Tenant
{
    return createTenant(['settings' => ['calendar' => 'ethiopian', 'leave_year' => $kind]]);
}

function leaveYearUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

// ── The year itself ──

test('the Ethiopian leave year runs Meskerem 1 to the end of Pagume', function () {
    $year = LeaveYear::of(LeaveYear::ETHIOPIAN);

    expect($year->yearOf(Carbon::parse('2026-09-10')))->toBe(2018)
        ->and($year->yearOf(Carbon::parse('2026-09-11')))->toBe(2019)
        ->and($year->starts(2019)->toDateString())->toBe('2026-09-11')
        ->and($year->monthOf(Carbon::parse('2026-11-02')))->toBe(2)
        // Pagume earns no thirteenth month: it belongs to the twelfth.
        ->and($year->monthOf(Carbon::parse('2027-09-08')))->toBe(12)
        ->and($year->monthEnds(2019, 12)->toDateString())->toBe('2027-09-11')
        ->and($year->monthEnds(2019, 2)->toDateString())->toBe('2026-11-09')
        ->and($year->dayOfYear(Carbon::parse('2026-09-11')))->toBe(1);

    $gregorian = LeaveYear::of(LeaveYear::GREGORIAN);
    expect($gregorian->yearOf(Carbon::parse('2026-09-11')))->toBe(2026)
        ->and($gregorian->starts(2026)->toDateString())->toBe('2026-01-01');
});

test('an organisation without the setting keeps the Gregorian year its balances are numbered in', function () {
    expect(LeaveYear::forTenant(createTenant(['settings' => []]))->kind)->toBe(LeaveYear::GREGORIAN);
});

test('a new organisation is given the Ethiopian leave year', function () {
    $tenant = createTenant(['settings' => []]);
    event(new TenantCreated($tenant, createUser(['role' => UserRole::TENANT_ADMIN], $tenant)));

    expect(LeaveYear::forTenant($tenant->fresh())->kind)->toBe(LeaveYear::ETHIOPIAN);
});

// ── Accrual ──

test('monthly accrual counts the months of the organisation\'s leave year', function (string $kind, int $year, float $entitled) {
    Carbon::setTestNow(Carbon::parse('2026-11-02 08:00', 'Africa/Addis_Ababa'));
    $tenant = leaveYearTenant($kind);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male', 'hire_date' => '2020-01-01']);
    $type = LeaveType::factory()->monthly()->create(['tenant_id' => $tenant->id, 'default_days' => 12]);

    (new LeaveBalanceService)->accrueMonthly($tenant->id);

    $balance = LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->sole();
    expect($balance->year)->toBe($year)
        ->and((float) $balance->entitled_days)->toBe($entitled);
})->with([
    // Tikimt is the second month of Ethiopian 2019.
    'Ethiopian' => [LeaveYear::ETHIOPIAN, 2019, 2.0],
    'Gregorian' => [LeaveYear::GREGORIAN, 2026, 11.0],
]);

test('someone hired in Meskerem accrues from Meskerem, not from January', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 08:00', 'Africa/Addis_Ababa'));
    $tenant = leaveYearTenant(LeaveYear::ETHIOPIAN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male', 'hire_date' => '2026-09-20']);
    $type = LeaveType::factory()->monthly()->create(['tenant_id' => $tenant->id, 'default_days' => 12]);

    (new LeaveBalanceService)->accrueMonthly($tenant->id);

    expect((float) LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->sole()->entitled_days)
        ->toBe(2.0);
});

// ── Requests and balances ──

test('a request in Meskerem draws on the new Ethiopian year', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 08:00', 'Africa/Addis_Ababa'));
    $tenant = leaveYearTenant(LeaveYear::ETHIOPIAN);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));
    $type = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'default_days' => 16, 'min_notice_days' => 0]);

    // Monday and Tuesday, 14 and 15 September: Meskerem 4 and 5, 2019.
    test()->postJson(leaveYearUrl($tenant, 'leave/request'), [
        'leave_type_public_id' => $type->public_id,
        'start_date' => '2026-09-14',
        'end_date' => '2026-09-15',
        'reason' => 'Family',
    ])->assertCreated();

    $balance = LeaveBalance::query()->where('employee_id', $employee->id)->sole();
    expect($balance->year)->toBe(2019)
        ->and((float) $balance->pending_days)->toBe(2.0);

    // Today, 1 September, is still Ethiopian 2018: its balance is what the
    // employee's own list shows by default.
    test()->getJson(leaveYearUrl($tenant, 'leave/balance'))->assertOk()->assertJsonCount(0);
    test()->getJson(leaveYearUrl($tenant, 'leave/balance?year=2019'))->assertOk()->assertJsonPath('0.year', 2019);
});

// ── The setting ──

test('settings show the leave year and change it while nothing would be lost', function () {
    $tenant = createTenant(['settings' => []]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    test()->getJson(leaveYearUrl($tenant, 'settings'))->assertOk()
        ->assertJsonPath('leave.leave_year', 'gregorian')
        ->assertJsonPath('leave.leave_year_changeable', true);

    test()->putJson(leaveYearUrl($tenant, 'settings'), ['settings' => ['leave_year' => 'ethiopian']])->assertOk();

    expect(LeaveYear::forTenant($tenant->fresh())->kind)->toBe(LeaveYear::ETHIOPIAN);
});

test('the leave year is locked once leave has been used', function () {
    $tenant = createTenant(['settings' => []]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'year' => 2026,
        'entitled_days' => 16,
        'used_days' => 3,
    ]);

    test()->getJson(leaveYearUrl($tenant, 'settings'))->assertJsonPath('leave.leave_year_changeable', false);

    test()->putJson(leaveYearUrl($tenant, 'settings'), ['settings' => ['leave_year' => 'ethiopian']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.leave_year']);

    // Saving the year it already has is not a change.
    test()->putJson(leaveYearUrl($tenant, 'settings'), ['settings' => ['leave_year' => 'gregorian']])->assertOk();
});

// ── Carry-over ──

/** @return array<int, array{0: int, 1: int}> tenant id => [from, to] */
function carriedOn(string $when): array
{
    Queue::fake();
    Carbon::setTestNow(Carbon::parse($when, 'Africa/Addis_Ababa'));
    collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'carry-forward-leave-balances')
        ->run(app());

    $years = [];
    foreach (Queue::pushed(CarryForwardLeaveBalancesJob::class) as $job) {
        $read = fn (string $p) => (new ReflectionProperty($job, $p))->getValue($job);
        $years[$read('tenantId')] = [$read('fromYear'), $read('toYear')];
    }

    return $years;
}

test('unused leave carries over in the first week of each organisation\'s own leave year', function () {
    $ethiopian = leaveYearTenant(LeaveYear::ETHIOPIAN);
    $gregorian = leaveYearTenant(LeaveYear::GREGORIAN);

    // Meskerem 3, 2019.
    expect(carriedOn('2026-09-13 09:00'))->toBe([$ethiopian->id => [2018, 2019]]);
    expect(carriedOn('2027-01-03 09:00'))->toBe([$gregorian->id => [2026, 2027]]);
    // Past the first week of either year, nothing.
    expect(carriedOn('2026-11-02 09:00'))->toBe([]);
});

<?php

declare(strict_types=1);

/**
 * Automated performance benchmarks (CLAUDE.md "Performance Targets" / PHASE_09 S37).
 * Not part of the default test run — invoke explicitly with:
 *   ./scripts/gates.sh performance
 * which runs `vendor/bin/pest tests/Performance` on native PHP. (It once ran inside
 * a Docker container, where a lossy bind mount collected only a fraction of the
 * suite and still exited 0; the Docker stack was removed on 2026-09-30.)
 * Seeds realistic data volumes and asserts response times as a regression guard,
 * not a precise production benchmark (this runs against SQLite in CI, not MariaDB).
 */

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Services\Report\TimesheetReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs the request 3 times and returns the fastest, to filter out transient
 * system noise (GC pauses, disk/AV contention) that isn't a code regression.
 * A genuine regression is slow on every run; a one-off hiccup isn't.
 * Pass $beforeEach to reset state (e.g. flush cache) ahead of every attempt,
 * so "best of 3" doesn't just measure a warm-cache run.
 */
function timedRequest(callable $request, ?callable $beforeEach = null): array
{
    $best = null;
    $bestMs = null;

    for ($i = 0; $i < 3; $i++) {
        if ($beforeEach) {
            $beforeEach();
        }

        $start = hrtime(true);
        $response = $request();
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        if ($bestMs === null || $elapsedMs < $bestMs) {
            $best = $response;
            $bestMs = $elapsedMs;
        }
    }

    return [$best, $bestMs];
}

/**
 * Assert the budget **and print what was actually measured**.
 *
 * `expect($ms)->toBeLessThan(500)` on its own answers "did it pass", which is
 * all a regression guard strictly needs — but it throws the number away, so a
 * route that quietly drifts from 20ms to 490ms stays green the whole way down
 * and nobody sees it coming. BASELINE §13e recorded "no performance baseline
 * exists" partly for that reason: the gate could pass without ever stating a
 * figure anyone could compare against later.
 *
 * Written to STDERR so it survives Pest's output capture and shows up in the
 * gate log next to the test it belongs to.
 */
function assertWithinBudget(float $ms, int $budgetMs, string $label): void
{
    fwrite(STDERR, sprintf(
        '    %-46s %8.1f ms   (budget %d ms, %.0f%% used)
',
        $label,
        $ms,
        $budgetMs,
        $ms / $budgetMs * 100,
    ));

    expect($ms)->toBeLessThan($budgetMs);
}

test('employee list handles 1000 rows with filtering and sorting under 500ms', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->count(1000)->create(['tenant_id' => $tenant->id]);

    [$response, $ms] = timedRequest(fn () => test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/employees?filter.status=confirmed&sort=-created_at&per_page=25",
    ));

    $response->assertOk();
    assertWithinBudget($ms, 500, 'employee list, 1000 rows, filtered+sorted');
});

test('employee fulltext search responds under 100ms', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->count(1000)->create(['tenant_id' => $tenant->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede Findable']);

    [$response, $ms] = timedRequest(fn () => test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/employees?search=Findable",
    ));

    $response->assertOk();
    assertWithinBudget($ms, 100, 'employee search (LIKE) over 1000 rows');
});

test('attendance list over 30 days responds under 300ms', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employees = Employee::factory()->count(50)->create(['tenant_id' => $tenant->id]);

    foreach ($employees as $employee) {
        for ($day = 0; $day < 30; $day++) {
            AttendanceRecord::factory()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'date' => now()->subDays($day)->format('Y-m-d'),
            ]);
        }
    }

    [$response, $ms] = timedRequest(fn () => test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/attendance?date_from=".now()->subDays(30)->format('Y-m-d').'&date_to='.now()->format('Y-m-d'),
    ));

    $response->assertOk();
    assertWithinBudget($ms, 300, 'attendance list, 50 employees x 30 days');
});

test('executive dashboard overview responds under 200ms on a cache miss', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->count(200)->create(['tenant_id' => $tenant->id]);

    [$response, $ms] = timedRequest(
        fn () => test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/executive"),
        beforeEach: fn () => Cache::flush(),
    );

    $response->assertOk();
    assertWithinBudget($ms, 200, 'executive dashboard, cache miss');
});

test('manager dashboard responds under 200ms', function () {
    $tenant = createTenant();
    $supervisor = actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    Employee::factory()->count(50)->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->employee_id,
    ]);

    [$response, $ms] = timedRequest(fn () => test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/manager",
    ));

    $response->assertOk();
    assertWithinBudget($ms, 200, 'manager dashboard');
});

// Query layer is fast (~6 queries, <10ms DB time regardless of entry count —
// verified via DB::getQueryLog(), no N+1). The remaining time is JsonResource
// serialization of the full unpaginated entries array, which is why this scales
// with entry count: at 150+ entries it exceeds 300ms even with the DB layer idle.
// Entries are unpaginated by design (a single payroll run's register), so very
// large tenants may want to paginate this endpoint — flagging for awareness,
// not fixing here since it's a response-shape change with frontend implications.
test('payroll run detail with entries responds under 300ms', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'status' => 'completed']);
    $employees = Employee::factory()->count(50)->create(['tenant_id' => $tenant->id]);

    foreach ($employees as $employee) {
        PayrollEntry::factory()->create([
            'tenant_id' => $tenant->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
        ]);
    }

    [$response, $ms] = timedRequest(fn () => test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}",
    ));

    $response->assertOk();
    assertWithinBudget($ms, 300, 'payroll run detail with entries');
});

test('leave balance calculation responds under 100ms', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
    ]);

    [$response, $ms] = timedRequest(fn () => test()->getJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/leave/balance",
    ));

    $response->assertOk();
    assertWithinBudget($ms, 100, 'leave balance calculation');
});

/**
 * A month of attendance for a thousand people: a record on each of the 22
 * weekdays of 2026-10, inserted in bulk. The host's limits are 30 seconds and
 * 128 MB a request, and the timesheet reads everyone in one pass (the page is
 * 25 people, the totals are over all 1,000), so this is the figure that says
 * whether a month of an organisation of that size fits.
 */
function seedTimesheetMonth(int $people): array
{
    $tenant = createTenant();
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);
    $employees = Employee::factory()->count($people)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    $rows = [];
    foreach ($employees as $employee) {
        for ($day = Carbon::parse('2026-10-01'); $day->lessThanOrEqualTo(Carbon::parse('2026-10-30')); $day = $day->copy()->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $date = $day->format('Y-m-d');
            $rows[] = [
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'date' => $date,
                'check_in' => Carbon::parse("$date 08:25", 'Africa/Addis_Ababa')->utc()->format('Y-m-d H:i:s'),
                'check_out' => Carbon::parse("$date 17:45", 'Africa/Addis_Ababa')->utc()->format('Y-m-d H:i:s'),
                'source' => 'web',
                'confidence_score' => 75,
                'status' => 'present',
                'idempotency_key' => (string) Str::uuid(),
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('attendance_records')->insert($chunk);
    }

    return [$tenant, count($rows)];
}

test('the monthly timesheet grid pages through 1000 people by a month inside the host limit', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'Africa/Addis_Ababa'));
    [$tenant, $records] = seedTimesheetMonth(1000);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Cache::flush();

    $start = hrtime(true);
    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/timesheet?from=2026-10-01&to=2026-10-31&per_page=25");
    $ms = (hrtime(true) - $start) / 1_000_000;

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1000)
        ->and($response->json('complete'))->toBeFalse();

    fwrite(STDERR, sprintf("    timesheet grid: %d people, %d records, peak memory %.0f MB\n", 1000, $records, memory_get_peak_usage(true) / 1_048_576));
    assertWithinBudget($ms, 30_000, 'timesheet grid page of 25, of 1000 people x 31 days');
    Carbon::setTestNow();
});

test('the monthly timesheet with totals, at the size limit, fits the host', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'Africa/Addis_Ababa'));
    [$tenant] = seedTimesheetMonth(TimesheetReport::MAX_PEOPLE);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Cache::flush();

    $start = hrtime(true);
    $grid = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/timesheet?from=2026-10-01&to=2026-10-31&per_page=25");
    $gridMs = (hrtime(true) - $start) / 1_000_000;
    $grid->assertOk();
    expect($grid->json('complete'))->toBeTrue();
    assertWithinBudget($gridMs, 30_000, 'timesheet grid with totals, '.TimesheetReport::MAX_PEOPLE.' people x 31 days');

    Cache::flush();
    $start = hrtime(true);
    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'timesheet',
        'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
    ]);
    $ms = (hrtime(true) - $start) / 1_000_000;

    $response->assertOk();
    expect($response->json('total'))->toBe(TimesheetReport::MAX_PEOPLE);

    assertWithinBudget($ms, 30_000, 'timesheet report source, '.TimesheetReport::MAX_PEOPLE.' people x 31 days');
    Carbon::setTestNow();
});

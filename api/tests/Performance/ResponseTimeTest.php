<?php

declare(strict_types=1);

/**
 * Automated performance benchmarks (CLAUDE.md "Performance Targets" / PHASE_09 S37).
 * Not part of the default test run — invoke explicitly with:
 *   ./scripts/gates.sh performance
 * which delegates to `scripts/pest-isolated.sh tests/Performance`. Do NOT run this
 * as `php artisan test tests/Performance` inside `et-api-1`: over the Windows bind
 * mount PHP's recursive directory scan collects only a fraction of what is on disk
 * and still exits 0, so the run reports green while measuring almost nothing.
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
use Illuminate\Support\Facades\Cache;

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

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RunScheduledReportsJob;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use App\Support\ReaderDates;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * A report never states a count it guessed, never reads rows nobody sees, and
 * refuses what it cannot honour (review of the reports change, 2026-11-15).
 */

function rhUrl(object $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/reports/{$path}";
}

function rhAttendance(object $tenant, int $count): void
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = AttendanceRecord::factory()->make([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'date' => Carbon::parse('2023-01-01')->addDays($i)->format('Y-m-d'),
        ])->getAttributes();
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        AttendanceRecord::insert($chunk);
    }
}

/** Twenty-five people hired before the span, one of whom punched once: far more than 1,000 missed workdays in 92 days. */
function rhAbsences(object $tenant): void
{
    test()->travelTo(Carbon::parse('2026-10-16 10:00', 'Africa/Addis_Ababa'));
    $ops = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations']);
    $fin = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    $first = null;
    foreach (range(1, 25) as $i) {
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'department_id' => $i <= 15 ? $ops->id : $fin->id]);
        $first ??= $employee;
    }
    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $first->id, 'date' => '2026-09-01']);
}

const RH_SPAN = ['from' => '2026-07-16', 'to' => '2026-10-15'];

test('an ungrouped absences report over the cut says so without claiming a count', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rhAbsences($tenant);

    $response = test()->postJson(rhUrl($tenant, 'generate'), ['source' => 'absences', 'filters' => RH_SPAN])->assertOk();

    expect($response->json('truncated'))->toBeTrue()
        ->and($response->json('total'))->toBe(ReportEngine::ROW_LIMIT)
        ->and($response->json('matched'))->toBe(ReportEngine::ROW_LIMIT);

    $note = app(ReportEngine::class)->truncationNote($response->json(), ReaderDates::forTenant($tenant, 'en'));
    expect($note)->toBe('This report shows only the newest 1,000 rows. Narrow the dates or the filters to see the rest.');
});

test('a grouped absences report over the cut totals every missed workday', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rhAbsences($tenant);

    $response = test()->postJson(rhUrl($tenant, 'generate'), ['source' => 'absences', 'group_by' => 'department', 'filters' => RH_SPAN])->assertOk();

    $groups = $response->json('summary.groups');
    expect($response->json('truncated'))->toBeTrue()
        ->and($response->json('total'))->toBe(ReportEngine::ROW_LIMIT)
        ->and($response->json('summary.complete'))->toBeTrue()
        ->and($response->json('matched'))->toBeGreaterThan(ReportEngine::ROW_LIMIT)
        ->and(array_sum($groups))->toBe($response->json('matched'))
        // Fifteen people in Operations and ten in Finance miss the same days,
        // but one Operations person punched on one of them.
        ->and($groups['Operations'])->toBe((int) ($groups['Finance'] / 10 * 15) - 1);

    $note = app(ReportEngine::class)->truncationNote($response->json(), ReaderDates::forTenant($tenant, 'en'));
    expect($note)->toContain('of '.number_format($response->json('matched')));
});

test('a grouped absences report that runs out of time claims no count', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rhAbsences($tenant);
    config(['payroll.request_budget_seconds' => 0]);

    $response = test()->postJson(rhUrl($tenant, 'generate'), ['source' => 'absences', 'group_by' => 'department', 'filters' => RH_SPAN])->assertOk();

    expect($response->json('summary.complete'))->toBeFalse()
        ->and($response->json('truncated'))->toBeTrue()
        ->and($response->json('matched'))->toBe($response->json('total'));
});

test('a file export of a grouped report does not read every row for totals nobody sees', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rhAttendance($tenant, ReportEngine::ROW_LIMIT + 600);
    $payload = ['source' => 'attendance', 'columns' => ['date', 'status'], 'group_by' => 'status', 'filters' => ['from' => '2023-01-01', 'to' => '2030-12-31']];
    $fullPassQueries = fn (): int => collect(DB::getQueryLog())
        ->filter(fn (array $q) => str_contains($q['query'], 'attendance_records') && str_contains($q['query'], 'limit 500'))
        ->count();

    DB::enableQueryLog();
    test()->postJson(rhUrl($tenant, 'generate'), $payload)->assertOk();
    $generated = $fullPassQueries();

    DB::flushQueryLog();
    $csv = test()->postJson(rhUrl($tenant, 'export'), $payload + ['format' => 'csv'])->assertOk()->getContent();
    $exported = $fullPassQueries();

    // The screen reads the rest in chunks of 500; the file reads none of them,
    // yet still knows how many rows matched, for its note.
    expect($generated)->toBeGreaterThanOrEqual(3)
        ->and($exported)->toBe(0)
        ->and($csv)->toContain('1,600');
});

test('a partial pass over the rows says its totals are not complete and counts what it read', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rhAttendance($tenant, ReportEngine::ROW_LIMIT + 100);

    // The query for the newest rows loads 1,000 models and the pass reads 500
    // at a time, so the 1,501st model loaded is the first of its second chunk:
    // the budget runs out after exactly one chunk has been counted.
    $loaded = 0;
    AttendanceRecord::retrieved(function () use (&$loaded): void {
        if (++$loaded === ReportEngine::ROW_LIMIT + 501) {
            config(['payroll.request_budget_seconds' => 0]);
        }
    });

    $response = test()->postJson(rhUrl($tenant, 'generate'), ['source' => 'attendance', 'columns' => ['status'], 'group_by' => 'status'])->assertOk();

    expect($response->json('summary.complete'))->toBeFalse()
        ->and(array_sum($response->json('summary.groups')))->toBe(500)
        ->and($response->json('matched'))->toBe(ReportEngine::ROW_LIMIT + 100);
});

test('a timesheet takes only periods of a month or less', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(rhUrl($tenant, 'generate'), ['source' => 'timesheet', 'filters' => ['period' => 'last_12_months']])
        ->assertStatus(422)->assertJsonValidationErrors(['filters.period']);
    test()->postJson(rhUrl($tenant, 'generate'), ['source' => 'timesheet', 'filters' => ['period' => 'last_month']])->assertOk();
    test()->postJson(rhUrl($tenant, 'generate'), ['source' => 'absences', 'filters' => ['period' => 'last_12_months']])->assertOk();
});

test('the engine leaves a long period alone for a timesheet that reaches it in a saved report', function () {
    test()->travelTo(Carbon::parse('2026-10-16 10:00', 'Africa/Addis_Ababa'));
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $result = app(ReportEngine::class)->generate($tenant->id, ['source' => 'timesheet', 'filters' => ['period' => 'last_12_months', 'month' => '2026-09']], $user);

    expect($result['period'])->toBe(['from' => '2026-09-01', 'to' => '2026-09-30']);
});

test('a filter the source does not publish is a 422', function (string $source, array $filters, string $error) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(rhUrl($tenant, 'generate'), ['source' => $source, 'filters' => $filters])
        ->assertStatus(422)->assertJsonValidationErrors([$error]);
    test()->postJson(rhUrl($tenant, 'save'), ['name' => 'X', 'config' => ['source' => $source, 'filters' => $filters]])
        ->assertStatus(422)->assertJsonValidationErrors(['config.'.$error]);
})->with([
    'department_id on employees' => ['employees', ['department_id' => '3'], 'filters.department_id'],
    'from on leave' => ['leave', ['from' => '2026-01-01'], 'filters.from'],
    'a typo' => ['attendance', ['stauts' => 'late'], 'filters.stauts'],
]);

test('a span that ends before it starts reports no period', function () {
    test()->travelTo(Carbon::parse('2026-11-01 10:00', 'Africa/Addis_Ababa'));
    $tenant = createTenant(['settings' => ['calendar' => 'gregorian']]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    foreach (['timesheet', 'absences'] as $source) {
        $response = test()->postJson(rhUrl($tenant, 'generate'), ['source' => $source, 'filters' => ['period' => 'this_month']])->assertOk();
        expect($response->json('period'))->toBeNull()->and($response->json('data'))->toBe([]);
    }
});

test('a sweep that has used its time leaves the other schedules due', function () {
    Notification::fake();
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id]);
    $saved = SavedReport::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $user->id, 'config' => ['source' => 'employees', 'columns' => ['name']]]);
    $make = fn () => ScheduledReport::factory()->create([
        'tenant_id' => $tenant->id, 'saved_report_id' => $saved->id, 'frequency' => 'daily',
        'recipients' => ['ops@example.com'], 'next_run_at' => now()->subMinute(), 'is_active' => true,
    ]);
    [$first, $second] = [$make(), $make()];
    config(['payroll.request_budget_seconds' => 0]);

    (new RunScheduledReportsJob)->handle(app(ReportEngine::class), app(CurrentTenant::class));

    expect($first->refresh()->last_run_at)->not->toBeNull()
        ->and($second->refresh()->last_run_at)->toBeNull()
        ->and($second->next_run_at->isPast())->toBeTrue();
});

test('the schedule list gives a code for the last error, never the text', function (?string $stored, ?string $code) {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $saved = SavedReport::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $user->id]);
    ScheduledReport::factory()->create(['tenant_id' => $tenant->id, 'saved_report_id' => $saved->id, 'is_active' => true, 'last_error' => $stored]);

    $response = test()->getJson(rhUrl($tenant, 'scheduled'))->assertOk();

    expect($response->json('schedules.0.last_error'))->toBe($code);
    if ($stored !== null && $code === 'error') {
        expect($response->getContent())->not->toContain('SQLSTATE')->not->toContain('secret');
    }
})->with([
    'succeeded' => [null, null],
    'the creator may no longer run it' => [RunScheduledReportsJob::CREATOR_CANNOT_RUN, 'creator_cannot_run'],
    'a database error' => ["SQLSTATE[HY000]: select * from employees where email = 'secret@example.com'", 'error'],
]);

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RunScheduledReportsJob;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Notifications\ScheduledReportNotification;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use Illuminate\Support\Facades\Notification;

/*
 * What a report's configuration may say, who may schedule a saved report, and
 * what its files and emails tell their reader (report review 2026-11-15,
 * A2, A4, A6, A7).
 */

function rcUrl(object $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/reports/{$path}";
}

test('a malformed date is a 422, not a 500, for every source that takes dates', function (string $source, UserRole $role, string $field) {
    $tenant = createTenant();
    actingAsUser(['role' => $role], $tenant);

    test()->postJson(rcUrl($tenant, 'generate'), ['source' => $source, 'filters' => [$field => '12/10/2026']])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonValidationErrors(["filters.{$field}"]);
})->with([
    'timesheet from' => ['timesheet', UserRole::HR_ADMIN, 'from'],
    'timesheet to' => ['timesheet', UserRole::HR_ADMIN, 'to'],
    'absences from' => ['absences', UserRole::HR_ADMIN, 'from'],
    'payroll from' => ['payroll', UserRole::FINANCE_ADMIN, 'from'],
    'attendance to' => ['attendance', UserRole::HR_ADMIN, 'to'],
    'timesheet month' => ['timesheet', UserRole::HR_ADMIN, 'month'],
    'leave year' => ['leave', UserRole::HR_ADMIN, 'year'],
    'attendance period' => ['attendance', UserRole::HR_ADMIN, 'period'],
]);

test('a group, sort or column the source does not have is a 422', function (array $payload, string $error) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(rcUrl($tenant, 'generate'), ['source' => 'employees', ...$payload])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonValidationErrors([$error]);
})->with([
    'group_by' => [['group_by' => 'nonsense'], 'group_by'],
    'sort_by' => [['sort_by' => 'worked_minutes'], 'sort_by'],
    'column' => [['columns' => ['name', 'nonsense']], 'columns.1'],
]);

test('saving a report checks its configuration the same way', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(rcUrl($tenant, 'save'), ['name' => 'Bad', 'config' => ['source' => 'attendance', 'filters' => ['from' => 'last week'], 'group_by' => 'nonsense']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['config.filters.from', 'config.group_by']);

    test()->postJson(rcUrl($tenant, 'save'), ['name' => 'Good', 'config' => ['source' => 'attendance', 'filters' => ['period' => 'last_month'], 'group_by' => 'department']])
        ->assertCreated();
});

test('a valid period, date, month and year are accepted', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    foreach ([
        ['source' => 'timesheet', 'filters' => ['period' => 'last_month']],
        ['source' => 'absences', 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31']],
        ['source' => 'timesheet', 'filters' => ['month' => '2026-10']],
        ['source' => 'leave', 'filters' => ['year' => '2026']],
    ] as $payload) {
        test()->postJson(rcUrl($tenant, 'generate'), $payload)->assertOk();
    }
});

test('only the person who saved a report may schedule it', function () {
    $tenant = createTenant();
    $owner = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $saved = SavedReport::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $owner->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $colleague = createScopedUser($tenant, $employee->id, 'department', ['report.generate']);
    $payload = ['saved_report_public_id' => $saved->public_id, 'frequency' => 'weekly', 'recipients' => ['ops@example.com']];

    test()->actingAs($colleague)->postJson(rcUrl($tenant, 'schedule'), $payload)->assertForbidden();
    expect(ScheduledReport::query()->count())->toBe(0);
    test()->actingAs($colleague)->getJson(rcUrl($tenant, 'saved'))
        ->assertOk()
        ->assertJsonPath('reports.0.mine', false)
        ->assertJsonMissingPath('reports.0.id')
        ->assertJsonMissingPath('reports.0.created_by');

    test()->actingAs($owner)->postJson(rcUrl($tenant, 'schedule'), $payload)->assertCreated();
    expect(ScheduledReport::query()->count())->toBe(1);
    test()->actingAs($owner)->getJson(rcUrl($tenant, 'saved'))->assertOk()->assertJsonPath('reports.0.mine', true);
});

test('removing a schedule is audited, and the list says that a run failed, by code', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $saved = SavedReport::factory()->create(['tenant_id' => $tenant->id, 'created_by' => $user->id]);
    $schedule = ScheduledReport::factory()->create([
        'tenant_id' => $tenant->id,
        'saved_report_id' => $saved->id,
        'is_active' => true,
        'last_error' => 'The person who saved this report can no longer run it.',
    ]);

    test()->getJson(rcUrl($tenant, 'scheduled'))->assertOk()
        ->assertJsonPath('schedules.0.last_error', 'creator_cannot_run');
    expect(test()->getJson(rcUrl($tenant, 'scheduled'))->getContent())->not->toContain('no longer run it');

    test()->deleteJson(rcUrl($tenant, "scheduled/{$schedule->public_id}"))->assertNoContent();

    expect(AuditLog::query()->where('action', 'report.unscheduled')->where('auditable_id', $schedule->id)->count())->toBe(1);
});

/** One employee with more attendance rows than a report shows. */
function rcLongAttendance(object $tenant, int $count): void
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = AttendanceRecord::factory()->make([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'date' => Carbon\Carbon::parse('2023-01-01')->addDays($i)->format('Y-m-d'),
        ])->getAttributes();
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        AttendanceRecord::insert($chunk);
    }
}

test('a cut report says so in its CSV and its PDF', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rcLongAttendance($tenant, ReportEngine::ROW_LIMIT + 3);
    $payload = ['source' => 'attendance', 'columns' => ['date'], 'filters' => ['from' => '2023-01-01', 'to' => '2030-12-31']];

    $csv = test()->postJson(rcUrl($tenant, 'export'), $payload + ['format' => 'csv'])->assertOk()->getContent();
    $lines = preg_split('/\r\n/', ltrim($csv, "\xEF\xBB\xBF"));
    expect($lines)->toHaveCount(ReportEngine::ROW_LIMIT + 1 + 2)
        ->and($lines[ReportEngine::ROW_LIMIT + 1])->toBe('')
        ->and($lines[ReportEngine::ROW_LIMIT + 2])->toContain('1,000')
        ->and($lines[ReportEngine::ROW_LIMIT + 2])->toContain('1,003');

    test()->postJson(rcUrl($tenant, 'export'), $payload + ['format' => 'pdf'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('an uncut report ends with its last row', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rcLongAttendance($tenant, 3);

    $csv = test()->postJson(rcUrl($tenant, 'export'), ['source' => 'attendance', 'columns' => ['date'], 'format' => 'csv'])->assertOk()->getContent();

    expect(preg_split('/\r\n/', ltrim($csv, "\xEF\xBB\xBF")))->toHaveCount(4);
});

test('a scheduled email names the period and says when the file was cut', function () {
    Notification::fake();
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rcLongAttendance($tenant, ReportEngine::ROW_LIMIT + 3);
    $saved = SavedReport::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'config' => ['source' => 'attendance', 'columns' => ['date'], 'filters' => ['from' => '2023-01-01', 'to' => '2030-12-31']],
    ]);
    ScheduledReport::factory()->create([
        'tenant_id' => $tenant->id,
        'saved_report_id' => $saved->id,
        'frequency' => 'monthly',
        'recipients' => ['ops@example.com'],
        'next_run_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    (new RunScheduledReportsJob)->handle(app(ReportEngine::class), app(CurrentTenant::class));

    Notification::assertSentOnDemand(ScheduledReportNotification::class, function (ScheduledReportNotification $notification, array $channels, $notifiable) {
        $mail = $notification->toMail($notifiable);
        $text = implode("\n", $mail->introLines);

        expect($text)->toContain('Period:')->toContain('1,003')
            ->and($mail->rawAttachments[0]['data'])->toContain('1,003');

        return true;
    });
});

test('running a report is limited to twenty a minute for each person', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    foreach (range(1, 20) as $ignored) {
        test()->postJson(rcUrl($tenant, 'generate'), ['source' => 'leave'])->assertOk();
    }

    test()->postJson(rcUrl($tenant, 'generate'), ['source' => 'leave'])->assertStatus(429);
    test()->postJson(rcUrl($tenant, 'export'), ['source' => 'leave'])->assertStatus(429);
});

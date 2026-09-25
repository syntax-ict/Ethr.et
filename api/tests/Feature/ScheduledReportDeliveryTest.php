<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RunScheduledReportsJob;
use App\Models\Employee;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Notifications\ScheduledReportFailedNotification;
use App\Notifications\ScheduledReportNotification;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use Illuminate\Support\Facades\Notification;

/**
 * `RunScheduledReportsJob` used to notify recipients with only a row count
 * (`SystemAlertNotification`) — the actual report data was generated and
 * then discarded, so a scheduled statutory filing report never reached
 * anyone with data they could submit. These cover the fix: the CSV export
 * is now attached to the delivery email.
 */
function scheduleReportFixture(array $recipients = ['ops@example.com']): array
{
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede', 'email' => 'abebe@example.com']);

    $saved = SavedReport::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'name' => 'Monthly Employee Report',
        'config' => ['source' => 'employees', 'columns' => ['name', 'email']],
    ]);

    $schedule = ScheduledReport::factory()->create([
        'tenant_id' => $tenant->id,
        'saved_report_id' => $saved->id,
        'frequency' => 'monthly',
        'recipients' => $recipients,
        'next_run_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    return [$tenant, $schedule];
}

test('delivers the actual report data as a csv attachment, not just a row count', function () {
    Notification::fake();
    [, $schedule] = scheduleReportFixture();

    (new RunScheduledReportsJob)->handle(app(ReportEngine::class), app(CurrentTenant::class));

    Notification::assertSentOnDemand(
        ScheduledReportNotification::class,
        function (ScheduledReportNotification $notification, array $channels, $notifiable) {
            if ($notifiable->routes['mail'] !== 'ops@example.com') {
                return false;
            }

            $mail = $notification->toMail($notifiable);
            expect($mail->rawAttachments)->toHaveCount(1);
            expect($mail->rawAttachments[0]['data'])->toContain('Abebe Kebede');
            expect($mail->rawAttachments[0]['data'])->toContain('abebe@example.com');
            expect($mail->rawAttachments[0]['name'])->toEndWith('.csv');
            expect($mail->rawAttachments[0]['options']['mime'])->toBe('text/csv');

            return true;
        },
    );

    $schedule->refresh();
    expect($schedule->last_run_at)->not->toBeNull();
    expect($schedule->next_run_at->isFuture())->toBeTrue();
});

test('does not attach an empty file when the report has no rows', function () {
    Notification::fake();
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $saved = SavedReport::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $user->id,
        'config' => ['source' => 'employees', 'columns' => ['name', 'email'], 'filters' => ['status' => 'terminated']],
    ]);
    ScheduledReport::factory()->create([
        'tenant_id' => $tenant->id,
        'saved_report_id' => $saved->id,
        'frequency' => 'daily',
        'recipients' => ['ops@example.com'],
        'next_run_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    (new RunScheduledReportsJob)->handle(app(ReportEngine::class), app(CurrentTenant::class));

    Notification::assertSentOnDemand(
        ScheduledReportNotification::class,
        function (ScheduledReportNotification $notification, array $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);

            return $mail->rawAttachments === [];
        },
    );
});

test('skips a malformed recipient address without failing the whole schedule', function () {
    Notification::fake();
    scheduleReportFixture(['not-an-email', 'ops@example.com']);

    (new RunScheduledReportsJob)->handle(app(ReportEngine::class), app(CurrentTenant::class));

    Notification::assertSentOnDemandTimes(ScheduledReportNotification::class, 1);
});

/**
 * A failed scheduled report used to be indistinguishable from a delivered one.
 *
 * `handle()` catches per-schedule failures so one tenant's broken definition
 * cannot stop everyone else's — correct, and it stays. But the catch then wrote
 * `last_run_at` and `next_run_at` with exactly the values the success path
 * writes, so the only trace of the failure was a log line. The API and UI
 * reported a successful run at the moment the report had delivered nothing.
 *
 * `docs/CLAUDE.md`'s queue-recovery table specified "mark export as failed,
 * notify requesting user" for the `exports` queue and recorded on 2026-09-22
 * that neither half was implemented. These cover both halves.
 */
test('a failed report records why on the schedule instead of looking like a success', function () {
    Notification::fake();
    [, $schedule] = scheduleReportFixture();

    // Mocked rather than driven through a deliberately broken report config:
    // this pins the job's behaviour when generation throws, whatever the engine
    // happens to reject today.
    $engine = mock(ReportEngine::class);
    $engine->shouldReceive('generate')->andThrow(new RuntimeException('report engine exploded'));

    (new RunScheduledReportsJob)->handle($engine, app(CurrentTenant::class));

    $schedule->refresh();

    expect($schedule->last_error)->toContain('report engine exploded');

    // The clock still advances. A permanently broken definition must not be
    // retried on every scheduler tick forever — that is why the catch exists,
    // and `last_error` is what makes advancing safe rather than silent.
    expect($schedule->last_run_at)->not->toBeNull();
    expect($schedule->next_run_at->isFuture())->toBeTrue();
});

test('tells the recipients the report did not arrive', function () {
    Notification::fake();
    [, $schedule] = scheduleReportFixture();

    $engine = mock(ReportEngine::class);
    $engine->shouldReceive('generate')->andThrow(new RuntimeException('report engine exploded'));

    (new RunScheduledReportsJob)->handle($engine, app(CurrentTenant::class));

    Notification::assertSentOnDemand(
        ScheduledReportFailedNotification::class,
        function (ScheduledReportFailedNotification $notification, array $channels, $notifiable) {
            if ($notifiable->routes['mail'] !== 'ops@example.com') {
                return false;
            }

            // The reason is deliberately absent. Recipients are free-text
            // addresses on the schedule, not authenticated users of the tenant,
            // and an exception message can carry column names, SQL fragments or
            // a connection string. The reason lives on `last_error`, behind the
            // tenant-scoped API.
            $mail = $notification->toMail($notifiable);
            $rendered = $mail->subject.' '.implode(' ', $mail->introLines);
            expect($rendered)->not->toContain('report engine exploded');
            expect($rendered)->toContain('Monthly Employee Report');

            return true;
        },
    );

    expect($schedule->refresh()->last_error)->not->toBeNull();
});

test('a later success clears the previous failure', function () {
    Notification::fake();
    [, $schedule] = scheduleReportFixture();

    // Left over from an earlier failed run, with the schedule due again.
    $schedule->forceFill([
        'last_error' => 'report engine exploded',
        'next_run_at' => now()->subMinute(),
    ])->save();

    (new RunScheduledReportsJob)->handle(app(ReportEngine::class), app(CurrentTenant::class));

    // `last_error` describes the LAST run, which is the only question its name
    // can answer. A schedule that failed yesterday and succeeded today must not
    // still read as failing.
    expect($schedule->refresh()->last_error)->toBeNull();
});

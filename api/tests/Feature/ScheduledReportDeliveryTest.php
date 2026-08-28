<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RunScheduledReportsJob;
use App\Models\Employee;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
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

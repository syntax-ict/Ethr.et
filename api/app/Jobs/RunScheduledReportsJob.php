<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\Tenant;
use App\Notifications\ScheduledReportNotification;
use App\Services\CurrentTenant;
use App\Services\Report\ReportEngine;
use App\Traits\SendsNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * PHASE_06 S29 — scheduled report delivery.
 *
 * `ScheduledReport` rows could be created (`ReportController::schedule`), listed
 * and deleted, but **nothing ever ran them**: no job, no command, no scheduler
 * entry referenced the model anywhere in the codebase. Users configured a daily
 * report and it never arrived, with no error to explain why.
 *
 * Runs on the `exports` queue — report generation is the same class of expensive
 * work, and CLAUDE.md's queue-failure table already routes `exports` failures to
 * "mark as failed, notify requesting user".
 */
class RunScheduledReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function handle(ReportEngine $engine, CurrentTenant $currentTenant): void
    {
        $due = ScheduledReport::withoutGlobalScopes()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->with('savedReport')
            ->get();

        foreach ($due as $schedule) {
            try {
                $this->run($schedule, $engine, $currentTenant);
            } catch (\Throwable $e) {
                // One tenant's broken report definition must not stop every other
                // tenant's scheduled reports for the day.
                Log::error('Scheduled report failed', [
                    'scheduled_report' => $schedule->public_id,
                    'error' => $e->getMessage(),
                ]);

                // Still advance the clock, or a permanently failing definition
                // would be retried on every single scheduler tick forever.
                $schedule->forceFill([
                    'last_run_at' => now(),
                    'next_run_at' => $this->nextRun($schedule->frequency),
                ])->save();
            }
        }
    }

    private function run(ScheduledReport $schedule, ReportEngine $engine, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($schedule->tenant_id);

        $saved = $schedule->savedReport;

        if ($tenant === null || ! $saved instanceof SavedReport) {
            return;
        }

        // A queue worker carries no tenant context, and the report engine reads
        // tenant-scoped models.
        $currentTenant->set($tenant);

        // Read through getAttribute: the `array` cast has a loose static type
        // (array|string) that otherwise defeats the array handling below — the
        // same workaround already documented in LoginRequest for tenant settings.
        $rawConfig = $saved->getAttribute('config');
        $config = is_array($rawConfig) ? $rawConfig : [];

        $result = $engine->generate($tenant->id, $config);
        $rows = $result['data'] ?? [];
        $rowCount = count($rows);
        $csv = $engine->toCsv($rows);
        $filename = Str::slug($saved->name).'-'.now()->format('Y-m-d').'.csv';

        $rawRecipients = $schedule->getAttribute('recipients');
        $recipients = array_filter(is_array($rawRecipients) ? $rawRecipients : []);

        foreach ($recipients as $email) {
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Anonymous notifiable: recipients are free-text addresses on the
            // schedule, not necessarily user accounts in this tenant.
            $this->notify(
                Notification::route('mail', $email),
                new ScheduledReportNotification($saved->name, $rowCount, $csv, $filename),
            );
        }

        $schedule->forceFill([
            'last_run_at' => now(),
            'next_run_at' => $this->nextRun($schedule->frequency),
        ])->save();

        Log::info('Scheduled report delivered', [
            'scheduled_report' => $schedule->public_id,
            'recipients' => count($recipients),
            'rows' => $rowCount,
        ]);
    }

    private function nextRun(?string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => now()->addDay(),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\Tenant;
use App\Notifications\ScheduledReportFailedNotification;
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
use Throwable;

/**
 * PHASE_06 S29 — scheduled report delivery.
 *
 * `ScheduledReport` rows could be created (`ReportController::schedule`), listed
 * and deleted, but **nothing ever ran them**: no job, no command, no scheduler
 * entry referenced the model anywhere in the codebase. Users configured a daily
 * report and it never arrived, with no error to explain why.
 *
 * Runs on the `exports` queue — report generation is the same class of expensive
 * work.
 *
 * NOTE, corrected 2026-09-22: this used to add that CLAUDE.md's queue-failure
 * table "already routes `exports` failures to 'mark as failed, notify requesting
 * user'". That was a specification, not a description — **this job defines no
 * `failed()` handler**, so on exhaustion nothing is marked and nobody is told;
 * only the `Queue::failing` hook logs. The table now records what actually
 * happens. Citing a spec as though it were behaviour is what let the gap sit
 * here unnoticed.
 */
class RunScheduledReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * Exception messages are unbounded; `scheduled_reports.last_error` is TEXT.
     * Truncating here rather than relying on the column keeps the behaviour the
     * same on SQLite (which would store the whole thing) and MariaDB.
     */
    private const MAX_ERROR = 2000;

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
            } catch (Throwable $e) {
                // One tenant's broken report definition must not stop every other
                // tenant's scheduled reports for the day.
                Log::error('Scheduled report failed', [
                    'scheduled_report' => $schedule->public_id,
                    'error' => $e->getMessage(),
                ]);

                // Still advance the clock, or a permanently failing definition
                // would be retried on every single scheduler tick forever.
                //
                // `last_error` is what makes that safe. Until 2026-09-25 this
                // branch wrote `last_run_at` and `next_run_at` with exactly the
                // values the success path writes, so a schedule that had
                // delivered nothing was indistinguishable from one that had
                // delivered — the failure existed only in a log line. Advancing
                // the clock silently is what turned an error into a success.
                $schedule->forceFill([
                    'last_run_at' => now(),
                    'last_error' => Str::limit($e->getMessage(), self::MAX_ERROR, ''),
                    'next_run_at' => $this->nextRun($schedule->frequency),
                ])->save();

                $this->notifyRecipientsOfFailure($schedule);
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

        // Cleared, not left alone: a schedule that failed yesterday and
        // succeeded today must not still read as failing. `last_error` describes
        // the LAST run, which is the only question its name can answer.
        $schedule->forceFill([
            'last_run_at' => now(),
            'last_error' => null,
            'next_run_at' => $this->nextRun($schedule->frequency),
        ])->save();

        Log::info('Scheduled report delivered', [
            'scheduled_report' => $schedule->public_id,
            'recipients' => count($recipients),
            'rows' => $rowCount,
        ]);
    }

    /**
     * Tell the recipients the report did not arrive.
     *
     * They are the only people positioned to notice: a statutory filing report
     * that silently stops arriving looks exactly like one nobody has opened.
     *
     * No try/catch here, and that is checked rather than assumed:
     * `SendsNotifications::notify()` already wraps the send in one and
     * downgrades any Throwable to a `Log::warning`. A second catch around it
     * would be unreachable code that reads like a safety net — which is how a
     * later reader ends up trusting a guard that never fires.
     *
     * What that guarantee buys here is the thing that matters: the schedule's
     * `last_error` is written before this runs, and nothing in this method can
     * throw it away.
     *
     * The reason is deliberately not sent; see ScheduledReportFailedNotification.
     */
    private function notifyRecipientsOfFailure(ScheduledReport $schedule): void
    {
        $saved = $schedule->savedReport;
        $name = $saved instanceof SavedReport ? $saved->name : '';

        $rawRecipients = $schedule->getAttribute('recipients');
        $recipients = array_filter(is_array($rawRecipients) ? $rawRecipients : []);

        foreach ($recipients as $email) {
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $this->notify(
                Notification::route('mail', $email),
                new ScheduledReportFailedNotification($name),
            );
        }
    }

    /**
     * The whole job exhausted its retries — a different event from one
     * schedule's definition being broken, which `handle()` already absorbs.
     *
     * Reaching here means the sweep itself died (the database went away, the
     * 600s timeout expired), so an unknown number of schedules were never
     * examined at all. There is no row to mark: the failure belongs to the run,
     * not to any one schedule, and marking every active schedule `last_error`
     * would blame definitions that are fine.
     *
     * No AuditLog, unlike ProcessPayrollJob::failed(). That job knows its one
     * tenant; this one sweeps every tenant with `withoutGlobalScopes()` and has
     * no tenant context to write against. A platform-level log is the honest
     * record, and `Queue::failing` in AppServiceProvider carries the rest.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Scheduled report sweep failed — an unknown number of schedules did not run', [
            'error' => $e->getMessage(),
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

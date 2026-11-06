<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AlertThreshold;
use App\Models\DashboardDigest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DashboardDigestFailedNotification;
use App\Notifications\DashboardDigestNotification;
use App\Services\Analytics\AlertEvaluator;
use App\Services\Analytics\ExecutiveDashboardService;
use App\Services\CurrentTenant;
use App\Support\ExecutiveBranchScope;
use App\Support\Locales;
use App\Support\ScheduleSlot;
use App\Traits\SendsNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Phase 6.6 — dashboard scheduling / digest delivery, deliberately not reusing
 * `RunScheduledReportsJob`: a digest has no `SavedReport`/`ReportEngine` config
 * behind it, it's `ExecutiveDashboardService::overview()`/`complianceSnapshot()`
 * computed fresh, so the two jobs share a shape (find due → deliver → advance
 * the clock, one tenant's failure doesn't stop another's) but not code.
 *
 * Runs on the `exports` queue — same class of "expensive, can wait, failure
 * should notify not retry-storm" work CLAUDE.md's queue table already routes
 * report generation through.
 */
class RunDashboardDigestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /** Mirrors RunScheduledReportsJob: the TEXT column is a backstop, not the limit. */
    private const MAX_ERROR = 2000;

    public function handle(ExecutiveDashboardService $service, AlertEvaluator $evaluator, CurrentTenant $currentTenant): void
    {
        $due = DashboardDigest::withoutGlobalScopes()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->get();

        foreach ($due as $digest) {
            try {
                $this->run($digest, $service, $evaluator, $currentTenant);
            } catch (Throwable $e) {
                Log::error('Dashboard digest failed', [
                    'dashboard_digest' => $digest->public_id,
                    'error' => $e->getMessage(),
                ]);

                // Advance the clock regardless, or a permanently-failing digest
                // (e.g. its branch was deleted) retries every scheduler tick forever.
                //
                // `last_error` is what makes that safe. Until 2026-09-25 this
                // branch wrote both timestamps with exactly the values the
                // success path writes, so a digest that had delivered nothing
                // read as delivered and the failure lived only in the line
                // above. Advancing silently is what turned an error into a
                // success.
                $digest->forceFill([
                    'last_run_at' => now(),
                    'last_error' => Str::limit($e->getMessage(), self::MAX_ERROR, ''),
                    'next_run_at' => ScheduleSlot::forTenantId($digest->frequency, $digest->tenant_id),
                ])->save();

                $this->notifyRecipientsOfFailure($digest);
            }
        }
    }

    private function run(DashboardDigest $digest, ExecutiveDashboardService $service, AlertEvaluator $evaluator, CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($digest->tenant_id);

        if ($tenant === null) {
            return;
        }

        // Not while the organisation is out of use: skipped to the next slot,
        // quietly, as a scheduled report is (A-Z review H-03).
        if (! $tenant->isActive()) {
            $digest->forceFill(['next_run_at' => ScheduleSlot::forTenantId($digest->frequency, $digest->tenant_id)])->save();

            return;
        }

        // A queue worker carries no tenant context, and the dashboard service
        // reads tenant-scoped models.
        $currentTenant->set($tenant);

        // A digest shows what the person who scheduled it may see, and stops
        // once they cannot: they left, or lost the dashboard, or moved branch
        // (A-Z review G-02). As a scheduled report stops with its author.
        $creator = $digest->creator()->first();
        $branchId = $digest->getAttribute('branch_id');
        $sees = $creator instanceof User && $creator->status === 'active'
            && (ExecutiveBranchScope::isFull($creator)
                || ($branchId !== null && ExecutiveBranchScope::isRegional($creator) && $creator->employee?->branch_id === $branchId));
        if (! $sees) {
            throw new RuntimeException('The person who scheduled this digest can no longer see it.');
        }

        $to = now();
        $from = $to->copy()->subDays(30);

        $overview = $service->overview($tenant->id, $from, $to, $branchId);
        $compliance = $service->complianceSnapshot($tenant->id, $branchId);
        $branchName = $digest->branch?->name;

        // Phase 6.7: fold in whatever's currently breached, evaluated against
        // this same overview/compliance data so a threshold can never disagree
        // with the numbers printed right above it in the same email.
        $thresholds = AlertThreshold::where('is_active', true)->get();
        $alerts = $evaluator->evaluate($overview, $compliance, $thresholds);

        $rawRecipients = $digest->getAttribute('recipients');
        $recipients = array_filter(is_array($rawRecipients) ? $rawRecipients : []);

        foreach ($recipients as $email) {
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Anonymous notifiable: recipients are free-text addresses on the
            // digest, not necessarily user accounts in this tenant — same
            // choice RunScheduledReportsJob makes for the identical reason.
            $this->notify(
                Notification::route('mail', $email),
                (new DashboardDigestNotification($digest->frequency, $overview, $compliance, $branchName, $alerts))->locale(self::organisationLocale($digest->tenant_id)),
            );
        }

        // Cleared, not left alone: `last_error` describes the LAST run, so a
        // digest that failed yesterday and succeeded today must not still read
        // as failing.
        $digest->forceFill([
            'last_run_at' => now(),
            'last_error' => null,
            'next_run_at' => ScheduleSlot::forTenantId($digest->frequency, $digest->tenant_id),
        ])->save();

        Log::info('Dashboard digest delivered', [
            'dashboard_digest' => $digest->public_id,
            'recipients' => count($recipients),
        ]);
    }

    /**
     * Tell the recipients the digest did not arrive.
     *
     * No try/catch: `SendsNotifications::notify()` already wraps the send and
     * downgrades any Throwable to a `Log::warning`, so a second one would be
     * unreachable code that reads like a safety net. What that buys here is the
     * thing that matters — the digest's `last_error` is written before this
     * runs, and nothing in this method can throw it away.
     */
    private function notifyRecipientsOfFailure(DashboardDigest $digest): void
    {
        $rawRecipients = $digest->getAttribute('recipients');
        $recipients = array_filter(is_array($rawRecipients) ? $rawRecipients : []);

        foreach ($recipients as $email) {
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $this->notify(
                Notification::route('mail', $email),
                (new DashboardDigestFailedNotification((string) $digest->frequency))->locale(self::organisationLocale($digest->tenant_id)),
            );
        }
    }

    /**
     * The whole sweep exhausted its retries — a different event from one
     * digest's definition being broken, which `handle()` already absorbs.
     *
     * Reaching here means an unknown number of digests were never examined at
     * all, so there is no row to mark: the failure belongs to the run, and
     * writing `last_error` across every active digest would blame definitions
     * that are fine. No AuditLog, for the same reason as
     * RunScheduledReportsJob::failed() — this job sweeps every tenant with
     * `withoutGlobalScopes()` and has no tenant context to write against.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Dashboard digest sweep failed — an unknown number of digests did not run', [
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * The language a free-text address is written to: the organisation's.
     * An anonymous recipient has no preference of its own, so these went out
     * in APP_LOCALE, English, to an Amharic organisation.
     */
    private static function organisationLocale(int|string|null $tenantId): string
    {
        $locale = $tenantId === null ? null : Tenant::query()->whereKey($tenantId)->value('default_locale');

        return Locales::orDefault($locale);
    }
}

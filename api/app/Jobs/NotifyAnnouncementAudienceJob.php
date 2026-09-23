<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * PHASE_05 S26: "Dispatches AnnouncementNotification to target audience."
 *
 * `AnnouncementNotification` shipped in Phase 5 and was never constructed, so
 * publishing an announcement notified nobody — it only appeared to users who
 * happened to open the announcements page.
 *
 * Queued because "all" on a large tenant is every employee.
 */
class NotifyAnnouncementAudienceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 2;

    /**
     * Declared rather than inherited: fans out one notification per audience member.
     *
     * Without this the job silently takes the worker's --timeout (60s by
     * default), and the queue retry_after invariant cannot be computed at all -
     * see tests/Feature/QueueRetryAfterInvariantTest.php.
     */
    public int $timeout = 600;

    /**
     * `$tenantId` is required, as of the §11j drain.
     *
     * It was nullable with a default from §11g until 2026-09-23, so that jobs
     * serialized before that deploy still unserialized — `unserialize()` does
     * not run the constructor, and an absent property takes the declared
     * default instead of staying uninitialized. That window is closed: the
     * queue is drained before this deploys (`docs/DEPLOYMENT.md` → *Draining
     * the queue before an upgrade*), so no payload without a tenant id can
     * still be in flight, and a conditional predicate is not a predicate the
     * query states.
     */
    public function __construct(
        private readonly int $announcementId,
        private readonly int $tenantId,
    ) {}

    public function handle(CurrentTenant $currentTenant): void
    {
        // States `tenant_id` itself rather than resting on the dispatcher. This
        // job goes on to call `$currentTenant->set()` from the row it finds and
        // then notifies that audience, so an id pointing at the wrong tenant
        // would not merely read across the boundary — it would send one
        // tenant's announcement to another tenant's employees.
        $announcement = Announcement::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($this->announcementId);

        if ($announcement === null) {
            return;
        }

        // Scheduled-for-later announcements are notified when they publish, not
        // when they are drafted.
        if ($announcement->published_at === null
            || Carbon::parse($announcement->published_at)->isFuture()) {
            return;
        }

        $tenant = $announcement->tenant;
        if ($tenant instanceof Tenant) {
            $currentTenant->set($tenant);
        }

        $recipients = $this->resolveAudience($announcement);

        if ($recipients->isEmpty()) {
            return;
        }

        $this->notify($recipients, new AnnouncementNotification($announcement));

        Log::info('Announcement notifications dispatched', [
            'announcement' => $announcement->public_id,
            'recipients' => $recipients->count(),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveAudience(Announcement $announcement): Collection
    {
        $query = User::withoutGlobalScopes()
            ->where('tenant_id', $announcement->tenant_id)
            ->where('status', 'active');

        return match ($announcement->target_type) {
            // Targeting joins through employees, so users with no employee record
            // (an HR-only login, say) fall outside a department or branch audience
            // — which is correct: they are not in that department.
            'department' => $query->whereHas(
                'employee',
                fn ($q) => $q->where('department_id', $announcement->target_id)
            )->get(),
            'branch' => $query->whereHas(
                'employee',
                fn ($q) => $q->where('branch_id', $announcement->target_id)
            )->get(),
            'role' => $query->where('role', $announcement->target_id)->get(),
            default => $query->get(),
        };
    }
}

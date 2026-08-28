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

    public function __construct(private readonly int $announcementId) {}

    public function handle(CurrentTenant $currentTenant): void
    {
        $announcement = Announcement::withoutGlobalScopes()->find($this->announcementId);

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

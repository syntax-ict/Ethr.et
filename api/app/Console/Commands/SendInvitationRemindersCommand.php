<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InvitationSend;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Invitations\InvitationDispatcher;
use Illuminate\Console\Command;

/**
 * Invites again anyone who has not activated a few days after their last
 * invitation (owner decision, 2026-10-28), at most twice, and only for
 * invitations from the last month: an account invited half a year ago and
 * never used is HR's to chase, not the scheduler's.
 *
 * Each organisation is read under its own tenant, so the sweep needs no scope
 * bypass. An organisation turns reminders off with the `invitation_reminders`
 * setting; unset means on.
 */
class SendInvitationRemindersCommand extends Command
{
    protected $signature = 'invitations:send-reminders';

    protected $description = 'Queue reminder invitations for people who have not activated their account';

    public function handle(CurrentTenant $currentTenant, InvitationDispatcher $dispatcher): int
    {
        $lastBefore = now()->subDays((int) config('invitations.reminder_after_days', 3));
        $invitedSince = now()->subDays((int) config('invitations.reminder_window_days', 30));
        $maxReminders = (int) config('invitations.max_reminders', 2);
        $queued = 0;

        try {
            Tenant::operational()->each(function (Tenant $tenant) use ($currentTenant, $dispatcher, $lastBefore, $invitedSince, $maxReminders, &$queued): void {
                if (data_get($tenant->settings, 'invitation_reminders', true) === false) {
                    return;
                }

                $currentTenant->set($tenant);

                $users = User::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'invited')
                    ->whereBetween('invited_at', [$invitedSince, $lastBefore])
                    // A reminder follows an invitation that went out. An account
                    // made with "send no invitation", or one invited before
                    // sends were recorded, is not chased.
                    ->whereHas('invitationSends', fn ($query) => $query->where('status', InvitationSend::SENT))
                    ->whereDoesntHave('invitationSends', fn ($query) => $query->where('queued_at', '>', $lastBefore))
                    ->withCount(['invitationSends as reminders_sent' => fn ($query) => $query->where('reminder', true)])
                    ->get()
                    ->filter(fn (User $user): bool => (int) $user->getAttribute('reminders_sent') < $maxReminders);

                if ($users->isNotEmpty()) {
                    $queued += $dispatcher->queue($users, null, reminder: true)['queued'];
                }
            });
        } finally {
            $currentTenant->forget();
        }

        $this->info("Queued {$queued} reminder invitations.");

        return self::SUCCESS;
    }
}

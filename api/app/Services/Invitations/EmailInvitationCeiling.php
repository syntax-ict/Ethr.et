<?php

declare(strict_types=1);

namespace App\Services\Invitations;

use App\Models\InvitationSend;
use App\Support\Mail\MailBudget;
use Illuminate\Support\Carbon;

/**
 * How many invitation emails a day may use of the mailbox every organisation
 * shares. Two ceilings, both a share of `mail.daily_cap` per Addis Ababa day:
 * one organisation's queued invitations (`invitations.email_org_share`) and
 * all organisations' together (`invitations.email_share`). A cap of 0 means no
 * ceiling.
 *
 * Both the dispatcher (planning a batch) and the job (a day that filled up
 * while it waited) ask here, so the arithmetic lives once.
 *
 * What is counted against an organisation is every invitation email it sent
 * today, including the ones sent by hand: those are never held, but they use
 * the day. What is counted against the platform is MailBudget's own counter,
 * so no query crosses organisations.
 */
final class EmailInvitationCeiling
{
    public const ZONE = 'Africa/Addis_Ababa';

    /** The hour a held invitation goes out on its day, Addis Ababa. */
    public const SEND_HOUR = 8;

    /** A queued row due longer ago than this has no live job: SendInvitationJob::retryUntil() is two days, plus a day of margin. */
    public const ABANDONED_AFTER_DAYS = 3;

    public function orgLimit(): ?int
    {
        return $this->limit((int) config('invitations.email_org_share', 20));
    }

    public function platformLimit(): ?int
    {
        return $this->limit((int) config('invitations.email_share', 40));
    }

    /** Invitation emails this organisation has sent since midnight in Addis Ababa. */
    public function sentToday(int $tenantId): int
    {
        return InvitationSend::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', InvitationSend::EMAIL)
            ->where('status', InvitationSend::SENT)
            ->where('sent_at', '>=', $this->startOfToday())
            ->count();
    }

    /** Whether one more queued email invitation may go out now. */
    public function roomToday(int $tenantId): bool
    {
        $orgLimit = $this->orgLimit();
        $platformLimit = $this->platformLimit();

        if ($orgLimit === null || $platformLimit === null) {
            return true;
        }

        return $this->sentToday($tenantId) < $orgLimit
            && MailBudget::invitationsToday() < $platformLimit;
    }

    /**
     * A planner for one organisation's days, reading its queued batch rows once.
     * `perMinute` is the organisation's sending pace (InvitationDispatcher::perMinute).
     * `fromTomorrow` starts the search the day after today, for a job that found
     * today full.
     */
    public function planner(int $tenantId, int $perMinute, bool $fromTomorrow = false): EmailDayPlanner
    {
        $limit = $this->orgLimit();
        if ($limit === null) {
            return new EmailDayPlanner(null, [], $perMinute, $fromTomorrow);
        }

        $today = now(self::ZONE)->toDateString();
        $used = [$today => $this->sentToday($tenantId)];

        $held = InvitationSend::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', InvitationSend::EMAIL)
            ->where('status', InvitationSend::QUEUED)
            ->whereNotNull('batch')
            ->get(['held_until', 'queued_at']);

        $abandonedBefore = now()->subDays(self::ABANDONED_AFTER_DAYS);

        foreach ($held as $row) {
            $heldUntil = $row->held_until;
            $due = $heldUntil ?? $row->queued_at;

            if ($due->lt($abandonedBefore)) {
                continue;
            }

            $day = $heldUntil !== null && $heldUntil->isFuture()
                ? $heldUntil->copy()->setTimezone(self::ZONE)->toDateString()
                : $today;
            $used[$day] = ($used[$day] ?? 0) + 1;
        }

        return new EmailDayPlanner($limit, $used, $perMinute, $fromTomorrow);
    }

    private function limit(int $share): ?int
    {
        $cap = MailBudget::cap();

        // Never 0: a day with no room at all would be planned forever.
        return $cap === 0 ? null : max(1, intdiv($cap * max(0, min(100, $share)), 100));
    }

    private function startOfToday(): Carbon
    {
        return now(self::ZONE)->startOfDay()->setTimezone((string) config('app.timezone', 'UTC'));
    }
}

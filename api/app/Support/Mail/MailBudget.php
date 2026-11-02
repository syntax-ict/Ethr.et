<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Notifications\Contracts\BulkMail;
use App\Notifications\Contracts\EssentialMail;
use App\Support\HealthSignals;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * The day's mail against the mailbox's daily send cap, which nothing respected
 * until 2026-11-02. Every organisation sends from one mailbox, so one payroll
 * release or one morning's missing-punch scan could use up the day, and past
 * the provider's cap a sign-in code fails like any other message.
 *
 * Three kinds of mail, by what the notification declares:
 *
 * - **Essential** (EssentialMail: sign-in codes, password resets, activation)
 *   is never held. It is counted, and the other two kinds stop early to leave
 *   it room.
 * - **Bulk** (BulkMail: one action or one scan mailing many people) stops at
 *   `mail.bulk_share` percent of the cap.
 * - **Everything else** stops at 90%, the last tenth kept for essential mail.
 *
 * Held means the email is skipped. The in-app notice is a separate channel
 * and still lands, so nothing is lost but the copy in the inbox.
 *
 * Counted per Ethiopian day from ResilientSmtpTransport, so only mail that
 * really reached the provider counts.
 */
final class MailBudget
{
    public const OTHER_SHARE = 90;

    /** Amber on the operations centre once the day passes this share of the cap. */
    public const NEAR_SHARE = 80;

    private const SENT = 'ethr:mail:sent:';

    private const HELD = 'ethr:mail:held:';

    private const TTL = 172800;

    /** The daily cap, or 0 when none is set. */
    public static function cap(): int
    {
        return max(0, (int) config('mail.daily_cap', 0));
    }

    public static function allows(Notification $notification): bool
    {
        $cap = self::cap();
        if ($cap === 0 || $notification instanceof EssentialMail) {
            return true;
        }

        $share = $notification instanceof BulkMail
            ? max(0, min(100, (int) config('mail.bulk_share', 60)))
            : self::OTHER_SHARE;

        return self::sentToday() < intdiv($cap * $share, 100);
    }

    public static function recordSent(): void
    {
        self::increment(self::SENT);
    }

    public static function recordHeld(): void
    {
        self::increment(self::HELD);
    }

    public static function sentToday(): int
    {
        return self::read(self::SENT);
    }

    public static function heldToday(): int
    {
        return self::read(self::HELD);
    }

    private static function key(string $prefix): string
    {
        return $prefix.now('Africa/Addis_Ababa')->format('Ymd');
    }

    /** A broken cache must never stop an email. */
    private static function increment(string $prefix): void
    {
        try {
            $store = HealthSignals::store();
            $key = self::key($prefix);
            $store->add($key, 0, self::TTL);
            $store->increment($key);
        } catch (Throwable) {
            // Reported by /health's own cache check.
        }
    }

    private static function read(string $prefix): int
    {
        try {
            return (int) HealthSignals::store()->get(self::key($prefix), 0);
        } catch (Throwable) {
            return 0;
        }
    }
}

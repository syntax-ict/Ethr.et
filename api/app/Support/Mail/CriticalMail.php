<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Jobs\RetryCriticalMail;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send a sign-in email now, and keep trying if now fails.
 *
 * Password reset, account activation and MFA reset were sent inline and, on a
 * mail failure, logged and dropped — the person saw "check your email" and
 * nothing ever came. They are still sent immediately (a queue that drains a
 * minute later is too slow for someone waiting at the sign-in page), and a
 * failure now queues an encrypted retry over the next hours
 * (RetryCriticalMail). The caller is never thrown at.
 *
 * Returns whether the email left now.
 */
final class CriticalMail
{
    public static function send(User $user, Notification $notification): bool
    {
        try {
            $user->notifyNow($notification);

            return true;
        } catch (Throwable $e) {
            Log::error('Sign-in email failed; retrying from the queue', [
                'user_id' => $user->id,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);

            // A platform account has no tenant to load it under; it is rare
            // and logged above. The dispatch is guarded too: on a `sync` queue
            // (tests, a local setup) the retry runs right here, and its
            // failure must not reach the caller.
            if ($user->tenant_id !== null) {
                try {
                    RetryCriticalMail::dispatch($user->id, (int) $user->tenant_id, $notification);
                } catch (Throwable $retry) {
                    Log::error('Sign-in email retry could not be queued', ['user_id' => $user->id, 'error' => $retry->getMessage()]);
                }
            }

            return false;
        }
    }
}

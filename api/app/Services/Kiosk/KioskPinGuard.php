<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KioskSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks the PIN a person types at a kiosk.
 *
 * Five wrong PINs for one person at one kiosk lock that person there for 15
 * minutes. The route limiter counts per IP, and a kiosk is one IP for everyone
 * in the queue, so it could neither stop a colleague guessing four digits nor
 * avoid locking out the whole queue. Punching, offline sync and self-service
 * share one counter: a guess spent on one is a guess spent on all three.
 */
final class KioskPinGuard
{
    public const ATTEMPTS = 5;

    public const LOCK_SECONDS = 900;

    public const OK = 'ok';

    public const MISSING = 'missing';

    public const LOCKED = 'locked';

    public const WRONG = 'wrong';

    /** @return self::OK|self::MISSING|self::LOCKED|self::WRONG */
    public function check(KioskSession $session, Employee $employee, ?string $pin, array $audit = []): string
    {
        if ($pin === null || $pin === '' || ! $employee->kiosk_pin) {
            return self::MISSING;
        }

        $key = $this->key($session, $employee);
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            return self::LOCKED;
        }

        if (! Hash::check($pin, $employee->kiosk_pin)) {
            if (RateLimiter::hit($key, self::LOCK_SECONDS) >= self::ATTEMPTS) {
                AuditLog::record('kiosk.pin_locked', $employee, ['kiosk_session_id' => $session->id, ...$audit]);
            }

            return self::WRONG;
        }

        RateLimiter::clear($key);

        return self::OK;
    }

    /** Seconds until a locked person may try again. */
    public function availableIn(KioskSession $session, Employee $employee): int
    {
        return RateLimiter::availableIn($this->key($session, $employee));
    }

    private function key(KioskSession $session, Employee $employee): string
    {
        return "kiosk-pin:{$session->id}:{$employee->id}";
    }
}

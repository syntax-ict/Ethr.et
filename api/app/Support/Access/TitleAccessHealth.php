<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Support\HealthSignals;

/**
 * What the held-title sweep (`access:watch-held-titles`) and its listener
 * last did, so "never ran", "ran and found nothing", "found it but had
 * already remembered it" and "dispatched, and the listener told nobody" are
 * four different readings in the operations centre instead of one silence.
 * Written by WatchHeldTitleAccessCommand and NotifyTitleAccessHeld.
 *
 * Counts and timestamps only, never an organisation name, a user id, an email
 * or a role: this is read through an operations endpoint, and the rule
 * MailHealth states for a public one is kept here so the signal can move to a
 * wider audience without a second review.
 */
final class TitleAccessHealth
{
    private const SWEEP_AT = 'ethr:title-access:last_sweep_at';

    private const SCANNED = 'ethr:title-access:tenants_scanned';

    private const ADMINS = 'ethr:title-access:admins';

    private const HELD = 'ethr:title-access:held';

    private const WAITING = 'ethr:title-access:waiting';

    private const DISPATCHED = 'ethr:title-access:dispatched';

    private const REMEMBERED = 'ethr:title-access:remembered';

    private const NOTICE_AT = 'ethr:title-access:last_notice_at';

    private const NOTICE_HELD = 'ethr:title-access:notice_held';

    private const NOTICE_NOTIFIED = 'ethr:title-access:notice_notified';

    private const TTL = 2592000;

    /**
     * `waiting` is counted after the admins' may-change test, so on its own a
     * zero has three causes. Read with the two counts before it:
     * `admins` 0 and `held` 0, the sweep's own queries found nothing at all;
     * `admins` above 0 and `held` 0, heldQuery() behaves differently here than
     * over HTTP; `held` above 0 and `waiting` 0, every admin was refused;
     * `waiting` above 0 and `dispatched` 0, the told-memory already holds it.
     */
    public static function recordSweep(int $tenantsScanned, int $admins, int $held, int $waiting, int $dispatched, int $remembered): void
    {
        self::write([
            self::SWEEP_AT => now()->toIso8601String(),
            self::SCANNED => $tenantsScanned,
            self::ADMINS => $admins,
            self::HELD => $held,
            self::WAITING => $waiting,
            self::DISPATCHED => $dispatched,
            self::REMEMBERED => $remembered,
        ]);
    }

    public static function recordNotice(int $held, int $notified): void
    {
        self::write([
            self::NOTICE_AT => now()->toIso8601String(),
            self::NOTICE_HELD => $held,
            self::NOTICE_NOTIFIED => $notified,
        ]);
    }

    /**
     * A broken cache must never stop the sweep or the notice: the record is a
     * convenience, the notice is the point.
     *
     * @param  array<string, string|int>  $values
     */
    private static function write(array $values): void
    {
        try {
            HealthSignals::store()->putMany($values, self::TTL);
        } catch (\Throwable) {
            // Reported by the operations centre's own cache check.
        }
    }

    /**
     * Nulls when the cache cannot be read: the operations centre already
     * reports a broken cache store, and must not fail on the way to saying so.
     *
     * @return array{last_sweep_at: string|null, tenants_scanned: int|null, admins: int|null, held: int|null, waiting: int|null, dispatched: int|null, remembered: int|null, last_notice_at: string|null, notice_held: int|null, notice_notified: int|null}
     */
    public static function snapshot(): array
    {
        $text = static function (string $key): ?string {
            try {
                $value = HealthSignals::store()->get($key);
            } catch (\Throwable) {
                return null;
            }

            return is_string($value) ? $value : null;
        };

        $count = static function (string $key): ?int {
            try {
                $value = HealthSignals::store()->get($key);
            } catch (\Throwable) {
                return null;
            }

            return is_int($value) ? $value : null;
        };

        return [
            'last_sweep_at' => $text(self::SWEEP_AT),
            'tenants_scanned' => $count(self::SCANNED),
            'admins' => $count(self::ADMINS),
            'held' => $count(self::HELD),
            'waiting' => $count(self::WAITING),
            'dispatched' => $count(self::DISPATCHED),
            'remembered' => $count(self::REMEMBERED),
            'last_notice_at' => $text(self::NOTICE_AT),
            'notice_held' => $count(self::NOTICE_HELD),
            'notice_notified' => $count(self::NOTICE_NOTIFIED),
        ];
    }
}

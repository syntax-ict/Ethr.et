<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * A server-issued permission to punch offline, fetched while the phone still
 * has signal and presented with every punch made without it.
 *
 * An offline punch is dated by the phone's own clock, which its owner can
 * set to anything. The grant is encrypted and authenticated with the
 * application key, names the employee and the moment the server issued it,
 * and expires after VALID_HOURS. A punch it covers must fall between that
 * issue time and the moment it reaches us, so winding the phone's clock back
 * can move a punch no earlier than the last time the phone was online - and
 * a punch with no grant, or someone else's, is flagged for review.
 *
 * Read by IntegrityInspector through the `offline_grant` metadata outcome.
 */
final class OfflineGrant
{
    public const VALID_HOURS = 72;

    /** Clock tolerance at the edges of the window. */
    private const SKEW_MINUTES = 2;

    /**
     * @return array{grant: string, issued_at: string, expires_at: string}
     */
    public function issue(Employee $employee): array
    {
        $issued = Carbon::now('UTC');
        $expires = $issued->copy()->addHours(self::VALID_HOURS);

        return [
            'grant' => Crypt::encryptString((string) json_encode([
                'e' => $employee->id,
                't' => $employee->tenant_id,
                'iat' => $issued->timestamp,
                'exp' => $expires->timestamp,
            ])),
            'issued_at' => $issued->toIso8601String(),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * `valid`, `missing` (none, or a legacy client's placeholder), `invalid`
     * (unreadable, another person's or another organisation's), or
     * `clock_outside_window` (the punch claims a time the grant cannot cover).
     */
    public function check(?string $grant, Employee $employee, Carbon $capturedAt, ?Carbon $receivedAt = null): string
    {
        if ($grant === null || $grant === '' || str_starts_with($grant, 'offline-')) {
            return 'missing';
        }

        try {
            $payload = json_decode(Crypt::decryptString($grant), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 'invalid';
        }

        if (! is_array($payload)
            || (int) ($payload['e'] ?? 0) !== $employee->id
            || (int) ($payload['t'] ?? 0) !== $employee->tenant_id) {
            return 'invalid';
        }

        $receivedAt ??= Carbon::now('UTC');
        $from = Carbon::createFromTimestamp((int) $payload['iat'], 'UTC')->subMinutes(self::SKEW_MINUTES);
        $until = Carbon::createFromTimestamp((int) $payload['exp'], 'UTC')->min($receivedAt)->addMinutes(self::SKEW_MINUTES);

        return $capturedAt->betweenIncluded($from, $until) ? 'valid' : 'clock_outside_window';
    }

    /** What is kept on the record: a fingerprint, not the grant itself. */
    public static function fingerprint(?string $grant): ?string
    {
        return $grant === null || $grant === '' ? null : substr(hash('sha256', $grant), 0, 40);
    }
}

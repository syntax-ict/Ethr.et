<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
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

    /** Punches one grant may cover; the phone fetches a fresh grant whenever it is online. */
    public const MAX_PUNCHES = 20;

    /**
     * With the employee's registered phone, the grant names its passkey and
     * carries a nonce: each offline punch is signed on the phone over the
     * nonce and its own fields (proofChallenge), so a punch made on another
     * phone, or edited after it was signed, does not verify at sync.
     *
     * @return array{grant: string, issued_at: string, expires_at: string, device_nonce: string|null, credential_id: string|null, rp_id: string|null}
     */
    public function issue(Employee $employee, ?AttendanceDevice $device = null): array
    {
        $issued = Carbon::now('UTC');
        $expires = $issued->copy()->addHours(self::VALID_HOURS);
        $nonce = $device === null ? null : rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        return [
            'grant' => Crypt::encryptString((string) json_encode(array_filter([
                'e' => $employee->id,
                't' => $employee->tenant_id,
                'iat' => $issued->timestamp,
                'exp' => $expires->timestamp,
                'c' => $device?->credential_id_hash,
                'n' => $nonce,
            ], static fn (mixed $value): bool => $value !== null))),
            'issued_at' => $issued->toIso8601String(),
            'expires_at' => $expires->toIso8601String(),
            'device_nonce' => $nonce,
            'credential_id' => $device?->credential_id,
            'rp_id' => $device?->rp_id,
        ];
    }

    /**
     * The grant's payload, or null when it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    public function payload(?string $grant): ?array
    {
        if ($grant === null || $grant === '' || str_starts_with($grant, 'offline-')) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($grant), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * What the phone signs for one offline punch: the grant's nonce and the
     * punch's own key, type, time and position, so none of them can be
     * changed after signing. Coordinates are fixed to six decimals on both
     * sides so the phone and the server hash the same text.
     *
     * @param  array<string, mixed>  $record
     */
    public static function proofChallenge(string $nonce, array $record): string
    {
        $coordinate = static fn (mixed $value): string => is_numeric($value) ? number_format((float) $value, 6, '.', '') : '';

        return hash('sha256', implode('|', [
            $nonce,
            (string) ($record['idempotency_key'] ?? ''),
            (string) ($record['type'] ?? ''),
            (string) ($record['timestamp'] ?? ''),
            $coordinate($record['latitude'] ?? null),
            $coordinate($record['longitude'] ?? null),
        ]), true);
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

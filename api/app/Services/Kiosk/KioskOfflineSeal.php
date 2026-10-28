<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use App\Models\KioskSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Offline punching at a kiosk.
 *
 * While online, the kiosk receives its public key and a grant. A punch made
 * while offline is sealed in the browser with RSA-OAEP under that key -
 * employee code, PIN, type, time and idempotency key together - and waits in
 * the terminal's storage until the connection returns. Only this server can
 * open it, so nothing that waits on the device can be read or changed there,
 * and the PIN is checked here at sync exactly as it would have been live.
 *
 * The grant bounds the kiosk's clock: a punch must fall between the moment
 * the grant was issued (the kiosk's last contact before going offline) and
 * its arrival here. One outside it is still recorded, and flagged.
 */
final class KioskOfflineSeal
{
    public const GRANT_VALID_DAYS = 7;

    private const SKEW_MINUTES = 2;

    /**
     * The kiosk's public key as base64 DER (SubjectPublicKeyInfo) - what
     * WebCrypto imports as `spki`. Created on first use.
     */
    public function publicKey(KioskSession $session): string
    {
        if (! is_string($session->offline_public_key) || $session->offline_public_key === '') {
            // The configuration is passed explicitly: a host whose
            // OPENSSL_CONF names a missing file cannot generate keys at all.
            $options = [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => resource_path('openssl/openssl.cnf'),
            ];
            $key = openssl_pkey_new($options);
            if ($key === false || ! openssl_pkey_export($key, $private, null, $options)) {
                throw new RuntimeException('Could not create the kiosk offline key.');
            }
            $details = openssl_pkey_get_details($key);
            if ($details === false) {
                throw new RuntimeException('Could not read the kiosk offline key.');
            }

            $session->forceFill([
                'offline_public_key' => $details['key'],
                'offline_private_key' => Crypt::encryptString($private),
            ])->save();
        }

        return self::pemToBase64Der((string) $session->offline_public_key);
    }

    /**
     * @return array{grant: string, issued_at: string}
     */
    public function grant(KioskSession $session): array
    {
        $issued = Carbon::now('UTC');

        return [
            'grant' => Crypt::encryptString((string) json_encode(['s' => $session->id, 'iat' => $issued->timestamp])),
            'issued_at' => $issued->toIso8601String(),
        ];
    }

    /**
     * The sealed punch, opened: `c` employee code, `p` PIN or null, `t` type,
     * `at` the moment it was made, `k` its idempotency key. Null when it
     * cannot be opened or is not a punch.
     *
     * @return array{c: string, p: string|null, t: string, at: string, k: string}|null
     */
    public function open(KioskSession $session, string $sealed): ?array
    {
        try {
            $private = Crypt::decryptString((string) $session->offline_private_key);
            $cipher = base64_decode($sealed, true);
            if ($cipher === false || ! openssl_private_decrypt($cipher, $plain, $private, OPENSSL_PKCS1_OAEP_PADDING)) {
                return null;
            }
            $payload = json_decode((string) $plain, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)
            || ! is_string($payload['c'] ?? null) || $payload['c'] === '' || strlen($payload['c']) > 64
            || ! in_array($payload['t'] ?? null, ['check_in', 'check_out', 'auto'], true)
            || ! is_string($payload['at'] ?? null)
            || ! is_string($payload['k'] ?? null) || $payload['k'] === '' || strlen($payload['k']) > 64
            || (isset($payload['p']) && ! is_string($payload['p']))) {
            return null;
        }

        return [
            'c' => $payload['c'],
            'p' => $payload['p'] ?? null,
            't' => $payload['t'],
            'at' => $payload['at'],
            'k' => $payload['k'],
        ];
    }

    /** `valid`, `missing`, `invalid` (unreadable or another kiosk's) or `clock_outside_window`. */
    public function checkGrant(?string $grant, KioskSession $session, Carbon $capturedAt): string
    {
        if ($grant === null || $grant === '') {
            return 'missing';
        }

        try {
            $payload = json_decode(Crypt::decryptString($grant), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 'invalid';
        }

        if (! is_array($payload) || (int) ($payload['s'] ?? 0) !== $session->id) {
            return 'invalid';
        }

        $issued = Carbon::createFromTimestamp((int) ($payload['iat'] ?? 0), 'UTC');
        $from = $issued->copy()->subMinutes(self::SKEW_MINUTES);
        $until = $issued->copy()->addDays(self::GRANT_VALID_DAYS)->min(Carbon::now('UTC'))->addMinutes(self::SKEW_MINUTES);

        return $capturedAt->betweenIncluded($from, $until) ? 'valid' : 'clock_outside_window';
    }

    private static function pemToBase64Der(string $pem): string
    {
        return (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }
}

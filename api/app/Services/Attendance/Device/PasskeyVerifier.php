<?php

declare(strict_types=1);

namespace App\Services\Attendance\Device;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * The passkey ceremonies, and the only class that touches the WebAuthn
 * library: options for the browser, and verification of what it signs.
 *
 * Options are built here in the JSON shape the browser library expects
 * (base64url strings), so challenges are ours to issue and store
 * (DeviceChallenges); the library only verifies. Only ES256 and RS256 are
 * offered: EdDSA needs sodium, which the host does not promise.
 *
 * The origin is checked strictly before the library sees it: the library
 * accepts any host that merely ends with the rp id, so `notethr.et` would
 * pass for `ethr.et`.
 */
final class PasskeyVerifier
{
    private const TIMEOUT_MS = 60000;

    /** Authenticator data flags (WebAuthn §6.1). */
    private const FLAG_BACKUP_STATE = 0x10;

    /**
     * @param  list<string>  $excludeCredentialIds  base64url
     * @return array<string, mixed>
     */
    public function registrationOptions(string $rpId, string $rpName, string $userHandle, string $userName, string $displayName, string $challenge, array $excludeCredentialIds = []): array
    {
        return [
            'rp' => ['name' => $rpName, 'id' => $rpId],
            'user' => ['id' => self::encode($userHandle), 'name' => $userName, 'displayName' => $displayName],
            'challenge' => self::encode($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey' => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => 'required',
            ],
            'excludeCredentials' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'public-key'],
                $excludeCredentialIds,
            ),
        ];
    }

    /**
     * @param  list<string>  $allowCredentialIds  base64url
     * @return array<string, mixed>
     */
    public function authenticationOptions(string $rpId, string $challenge, array $allowCredentialIds): array
    {
        return [
            'challenge' => self::encode($challenge),
            'rpId' => $rpId,
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'required',
            'allowCredentials' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'public-key'],
                $allowCredentialIds,
            ),
        ];
    }

    /**
     * Verify a registration response (the browser library's
     * RegistrationResponseJSON) against the challenge that was issued.
     *
     * @param  array<string, mixed>  $response
     * @return array{credential_id: string, public_key: string, sign_count: int, aaguid: string|null, backup_eligible: bool, backup_state: bool}
     *
     * @throws PasskeyRejected
     */
    public function verifyRegistration(array $response, string $rpId, string $challenge): array
    {
        $clientData = self::decode((string) data_get($response, 'response.clientDataJSON', ''));
        $attestation = self::decode((string) data_get($response, 'response.attestationObject', ''));
        $this->assertOrigin($clientData, $rpId);

        try {
            $data = $this->library($rpId)->processCreate($clientData, $attestation, $challenge, true, true, false, false);
        } catch (WebAuthnException $e) {
            throw new PasskeyRejected($e->getMessage(), previous: $e);
        }

        $credentialId = self::encode((string) $data->credentialId);
        if ($credentialId !== (string) data_get($response, 'id', '')) {
            throw new PasskeyRejected('credential id mismatch');
        }
        if (! is_string($data->credentialPublicKey) || $data->credentialPublicKey === '') {
            throw new PasskeyRejected('unsupported key type');
        }

        $aaguid = is_string($data->AAGUID) && strlen($data->AAGUID) === 16 ? self::uuid($data->AAGUID) : null;

        return [
            'credential_id' => $credentialId,
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0),
            'aaguid' => $aaguid,
            'backup_eligible' => (bool) ($data->isBackupEligible ?? false),
            'backup_state' => (bool) ($data->isBackedUp ?? false),
        ];
    }

    /**
     * The challenge an assertion claims to answer, read from its client data
     * so it can be looked up and spent before the signature is checked.
     *
     * @param  array<string, mixed>  $response
     */
    public function claimedChallenge(array $response): ?string
    {
        $clientData = json_decode(self::decode((string) data_get($response, 'response.clientDataJSON', '')), true);
        $challenge = is_array($clientData) ? ($clientData['challenge'] ?? null) : null;

        return is_string($challenge) && $challenge !== '' ? self::decode($challenge) : null;
    }

    /**
     * Verify an assertion (AuthenticationResponseJSON) made with a stored
     * public key. A counter of 0 is what synced passkeys always send, so it
     * is not compared.
     *
     * @param  array<string, mixed>  $response
     * @return array{sign_count: int, backup_state: bool}
     *
     * @throws PasskeyRejected
     */
    public function verifyAssertion(array $response, string $rpId, string $publicKey, int $previousCount, string $challenge): array
    {
        $clientData = self::decode((string) data_get($response, 'response.clientDataJSON', ''));
        $authenticatorData = self::decode((string) data_get($response, 'response.authenticatorData', ''));
        $signature = self::decode((string) data_get($response, 'response.signature', ''));
        $this->assertOrigin($clientData, $rpId);

        $library = $this->library($rpId);
        try {
            $library->processGet($clientData, $authenticatorData, $signature, $publicKey, $challenge, $previousCount > 0 ? $previousCount : null, true, true);
        } catch (WebAuthnException $e) {
            throw new PasskeyRejected($e->getMessage(), previous: $e);
        }

        return [
            'sign_count' => (int) ($library->getSignatureCounter() ?? 0),
            'backup_state' => strlen($authenticatorData) > 32 && (ord($authenticatorData[32]) & self::FLAG_BACKUP_STATE) !== 0,
        ];
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $base64url): string
    {
        $decoded = base64_decode(strtr($base64url, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * The client data's origin must be the rp id itself or a real subdomain
     * of it, over https (http only for localhost in development).
     *
     * @throws PasskeyRejected
     */
    private function assertOrigin(string $clientDataJson, string $rpId): void
    {
        $origin = json_decode($clientDataJson, true)['origin'] ?? null;
        $parts = is_string($origin) ? parse_url($origin) : false;
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $rp = strtolower($rpId);

        $hostMatches = $host === $rp || str_ends_with($host, '.'.$rp);
        $schemeAllowed = $scheme === 'https' || ($scheme === 'http' && $rp === 'localhost');

        if (! $hostMatches || ! $schemeAllowed) {
            throw new PasskeyRejected('invalid origin');
        }
    }

    private function library(string $rpId): WebAuthn
    {
        return new WebAuthn('ETHR', $rpId, null, true);
    }

    private static function uuid(string $binary): string
    {
        $hex = bin2hex($binary);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}

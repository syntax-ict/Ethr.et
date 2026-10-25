<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A software passkey for tests: a real P-256 key made with openssl, producing
 * the same base64url JSON a browser hands the server (RegistrationResponseJSON
 * with a `none` attestation, AuthenticationResponseJSON), so verification runs
 * the production code end to end.
 */
final class SoftAuthenticator
{
    private \OpenSSLAsymmetricKey $key;

    public string $credentialId;

    private int $counter = 0;

    public function __construct(public string $origin = 'https://acme.ethr.test', public bool $synced = true)
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = openssl_pkey_new($options);
        // PHP on Windows needs to be told where openssl.cnf is.
        $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if ($key === false && is_file($config)) {
            $key = openssl_pkey_new([...$options, 'config' => $config]);
        }
        if ($key === false) {
            throw new RuntimeException('openssl could not make a P-256 key');
        }
        $this->key = $key;
        $this->credentialId = self::b64(random_bytes(32));
    }

    /**
     * @param  array<string, mixed>  $options  the server's creation options
     * @return array<string, mixed>
     */
    public function register(array $options): array
    {
        $rpId = (string) $options['rp']['id'];
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => (string) $options['challenge'],
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $details = openssl_pkey_get_details($this->key);
        $cose = self::cborMap([
            [1, 2],          // kty: EC2
            [3, -7],         // alg: ES256
            [-1, 1],         // crv: P-256
            [-2, self::bytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))],
            [-3, self::bytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))],
        ]);
        $rawId = self::unb64($this->credentialId);
        $authData = hash('sha256', $rpId, true)
            .chr($this->flags(attested: true))
            .pack('N', 0)
            .str_repeat("\0", 16)
            .pack('n', strlen($rawId)).$rawId
            .$cose;

        $attestation = self::cborMap([
            ['fmt', 'none'],
            ['attStmt', self::RAW_EMPTY_MAP],
            ['authData', self::bytes($authData)],
        ]);

        return [
            'id' => $this->credentialId,
            'rawId' => $this->credentialId,
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'response' => [
                'clientDataJSON' => self::b64((string) $clientData),
                'attestationObject' => self::b64($attestation),
                'transports' => ['internal', 'hybrid'],
            ],
            'clientExtensionResults' => [],
        ];
    }

    /**
     * Sign an assertion for a challenge: the server's base64url challenge, or
     * raw bytes for an offline punch.
     *
     * @return array<string, mixed>
     */
    public function sign(string $rpId, string $challengeB64, ?string $origin = null): array
    {
        $clientData = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challengeB64,
            'origin' => $origin ?? $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $authData = hash('sha256', $rpId, true).chr($this->flags()).pack('N', $this->synced ? 0 : ++$this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->credentialId,
            'rawId' => $this->credentialId,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64((string) $signature),
                'userHandle' => null,
            ],
            'clientExtensionResults' => [],
        ];
    }

    public static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }

    private function flags(bool $attested = false): int
    {
        // UP, UV, and backup eligible/state for a synced passkey.
        $flags = 0x01 | 0x04;
        if ($this->synced) {
            $flags |= 0x08 | 0x10;
        }

        return $attested ? $flags | 0x40 : $flags;
    }

    private const RAW_EMPTY_MAP = "\xA0";

    /** @param list<array{0: int|string, 1: mixed}> $pairs */
    private static function cborMap(array $pairs): string
    {
        $out = self::head(5, count($pairs));
        foreach ($pairs as [$key, $value]) {
            $out .= self::cbor($key).self::cbor($value);
        }

        return $out;
    }

    private static function cbor(mixed $value): string
    {
        if ($value instanceof CborBytes) {
            return self::head(2, strlen($value->bytes)).$value->bytes;
        }
        if ($value === self::RAW_EMPTY_MAP) {
            return self::RAW_EMPTY_MAP;
        }
        if (is_int($value)) {
            return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
        }
        if (is_string($value)) {
            return self::head(3, strlen($value)).$value;
        }

        throw new RuntimeException('unsupported CBOR value');
    }

    private static function head(int $major, int $length): string
    {
        $type = $major << 5;
        if ($length < 24) {
            return chr($type | $length);
        }
        if ($length < 256) {
            return chr($type | 24).chr($length);
        }

        return chr($type | 25).pack('n', $length);
    }

    private static function bytes(string $bytes): CborBytes
    {
        return new CborBytes($bytes);
    }
}

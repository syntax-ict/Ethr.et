<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Encrypts a backup archive before it leaves the host, and decrypts it on the
 * way back.
 *
 * An off-host copy is every salary, bank account and scanned identity
 * document of every organisation, sitting with a storage provider. Whoever
 * holds the bucket credentials, or the provider's staff, would otherwise read
 * it in full. The key (BACKUP_ENCRYPTION_KEY) is never stored with the copy,
 * so the copy alone is useless.
 *
 * libsodium's secretstream (XChaCha20-Poly1305), in 1 MB chunks, so memory
 * stays flat on a 128M host whatever the size of the document store. Every
 * chunk is authenticated, and the last carries the FINAL tag: a file cut short
 * in transit fails to decrypt instead of restoring part of the data.
 *
 * File layout: the 8-byte MAGIC, the 24-byte stream header, then each chunk as
 * a 4-byte big-endian length followed by that many bytes of ciphertext.
 */
final class BackupCipher
{
    public const MAGIC = 'ETHRBAK1';

    public const EXTENSION = '.ethrbak';

    private const CHUNK_BYTES = 1048576;

    public function __construct(private readonly string $key) {}

    /**
     * The configured key, or null when BACKUP_ENCRYPTION_KEY is unset or not a
     * 32-byte key in base64. Callers treat null as "do not copy off-host".
     */
    public static function fromConfig(): ?self
    {
        $key = self::decodeKey((string) config('backup.encryption_key', ''));

        return $key === null ? null : new self($key);
    }

    public static function decodeKey(string $configured): ?string
    {
        $configured = trim($configured);

        if (str_starts_with($configured, 'base64:')) {
            $configured = substr($configured, 7);
        }

        $key = base64_decode($configured, true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES ? $key : null;
    }

    /** A fresh key in the form BACKUP_ENCRYPTION_KEY expects. */
    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public function encryptFile(string $source, string $target): void
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($target, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            fwrite($out, self::MAGIC.$header);

            $chunk = (string) fread($in, self::CHUNK_BYTES);

            // Read one chunk ahead, so the last one is known to be last and
            // carries the FINAL tag even when the file is an exact multiple of
            // the chunk size.
            do {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK_BYTES);
                $tag = $next === ''
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                fwrite($out, pack('N', strlen($cipher)).$cipher);

                $chunk = $next;
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decryptFile(string $source, string $target): void
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($target, 'wb');

        try {
            $magic = (string) fread($in, strlen(self::MAGIC));

            if ($magic !== self::MAGIC) {
                throw new RuntimeException('Not an ETHR backup archive: '.basename($source).'.');
            }

            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            $finished = false;

            while (! $finished) {
                $length = fread($in, 4);

                if ($length === false || strlen($length) !== 4) {
                    throw new RuntimeException('The backup archive ends before its last chunk: it was cut short.');
                }

                $cipher = (string) fread($in, (int) unpack('N', $length)[1]);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

                if ($result === false) {
                    throw new RuntimeException('The backup archive does not decrypt: wrong BACKUP_ENCRYPTION_KEY, or the file is damaged.');
                }

                [$plain, $tag] = $result;
                fwrite($out, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } catch (RuntimeException $e) {
            fclose($out);
            @unlink($target);

            throw $e;
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        return $handle;
    }
}

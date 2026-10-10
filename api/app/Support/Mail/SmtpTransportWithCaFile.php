<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * The framework's SMTP transport, plus one option it does not expose: a CA
 * file to verify the server's certificate against (`mail.mailers.smtp.ca_file`,
 * MAIL_CA_FILE).
 *
 * Why it exists (measured 2026-10-10): the production host's mail provider
 * serves a certificate without its intermediate. A browser fetches the missing
 * link itself; PHP does not, so every send failed with "certificate verify
 * failed" and the password-reset mail never left. The fix that keeps the peer
 * verified is to supply that intermediate, and Laravel's SMTP config has no key
 * for it. Turning verification off would have worked too — and would have
 * handed the mailbox password to anyone on the path.
 *
 * With no CA file configured this is exactly the framework transport.
 */
final class SmtpTransportWithCaFile
{
    /**
     * @param  array<string, mixed>  $config  the mailer's config, as MailManager passes it
     */
    public static function create(Application $app, array $config): EsmtpTransport
    {
        $transport = (new class($app) extends MailManager
        {
            /** @param  array<string, mixed>  $config */
            public function smtp(array $config): EsmtpTransport
            {
                return $this->createSmtpTransport($config);
            }
        })->smtp($config);

        $ssl = self::sslOptions($config);
        $stream = $transport->getStream();

        if ($ssl !== [] && $stream instanceof SocketStream) {
            $stream->setStreamOptions(array_replace_recursive($stream->getStreamOptions(), ['ssl' => $ssl]));
        }

        return $transport;
    }

    /**
     * The TLS options this class adds, shared with `ethr:mail-probe` so the
     * probe verifies exactly as the mailer does.
     *
     * MAIL_PEER_FINGERPRINT (`peer_sha256`) pins the server's certificate by
     * its SHA-256. Measured 2026-10-10: the production provider's SMTP port
     * serves a certificate that EXPIRED on 2026-02-11, which no CA file can
     * make verify. A pin still refuses any other certificate (PHP compares the
     * fingerprint even with chain verification off, checked against the live
     * server), so mail goes only to that server, encrypted. It is a stopgap:
     * when the provider installs its renewed certificate the pin stops
     * matching, every send fails at `error` level, and the pin comes out.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function sslOptions(array $config): array
    {
        $pin = self::pinnedFingerprint($config['peer_sha256'] ?? null);

        if ($pin !== null) {
            return [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'peer_fingerprint' => ['sha256' => $pin],
            ];
        }

        $path = self::caFilePath($config['ca_file'] ?? null);

        return $path === null ? [] : ['cafile' => $path];
    }

    /**
     * A SHA-256 fingerprint in any common spelling (colons, spaces, case),
     * normalised to 64 lowercase hex characters. Null when unset; anything
     * else that is set is refused rather than quietly ignored, because an
     * ignored pin would fall back to a check that cannot pass.
     */
    public static function pinnedFingerprint(mixed $value): ?string
    {
        $raw = (string) ($value ?? '');

        if (trim($raw) === '') {
            return null;
        }

        $hex = strtolower((string) preg_replace('/[\s:]/', '', $raw));

        if (preg_match('/^[0-9a-f]{64}$/', $hex) !== 1) {
            throw new InvalidArgumentException('MAIL_PEER_FINGERPRINT must be a SHA-256 fingerprint: 64 hex characters.');
        }

        return $hex;
    }

    /**
     * MAIL_CA_FILE as a path: absolute as given, otherwise relative to api/.
     * Null when unset. Shared with `ethr:mail-probe`, so the probe checks the
     * file the mailer actually uses.
     */
    public static function caFilePath(mixed $caFile): ?string
    {
        $caFile = (string) ($caFile ?? '');

        if ($caFile === '') {
            return null;
        }

        return str_starts_with($caFile, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $caFile) === 1
            ? $caFile
            : base_path($caFile);
    }
}

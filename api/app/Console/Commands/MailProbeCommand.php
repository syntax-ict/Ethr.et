<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\SmtpTransportWithCaFile;
use Illuminate\Console\Command;

/**
 * Connects to the configured SMTP server the way the mailer does, sends no
 * mail and does not log in, and reports the certificate chain the server
 * presents and whether it verifies (with MAIL_CA_FILE when set).
 *
 * Why it exists (2026-10-10): the production mail server's TLS port answers
 * only inside the provider's network, so the chain that failed with
 * "certificate verify failed" could not be seen from anywhere else. The host
 * has no shell; this runs through the maintenance endpoint as `mail-probe`.
 * Everything it prints is public certificate data.
 */
class MailProbeCommand extends Command
{
    protected $signature = 'ethr:mail-probe {--timeout=15 : Seconds to wait for each connection}';

    protected $description = 'Show the SMTP server certificate chain and whether it verifies (sends nothing)';

    public function handle(): int
    {
        $config = (array) config('mail.mailers.smtp');
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 0);
        $implicitTls = ($config['scheme'] ?? null) === 'smtps' || $port === 465;
        $caFile = SmtpTransportWithCaFile::caFilePath($config['ca_file'] ?? null);
        $timeout = (float) $this->option('timeout');

        $this->line(sprintf('Server: %s:%d (%s)', $host, $port, $implicitTls ? 'implicit TLS' : 'STARTTLS'));
        $this->line('CA file: '.($caFile === null ? '(none: system trust store)' : $caFile.(is_readable($caFile) ? '' : '  [NOT READABLE]')));
        $this->line('Pinned SHA-256: '.(SmtpTransportWithCaFile::pinnedFingerprint($config['peer_sha256'] ?? null) ?? '(none)'));

        // 1. Unverified, to see what the server presents.
        [$stream, $error] = $this->connect($host, $port, $implicitTls, $timeout, [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert_chain' => true,
        ]);

        if ($stream === null) {
            $this->error('Could not complete a TLS connection: '.$error);

            return self::FAILURE;
        }

        $chain = stream_context_get_params($stream)['options']['ssl']['peer_certificate_chain'] ?? [];
        fclose($stream);

        $this->line(sprintf('Chain presented: %d certificate(s)', count($chain)));
        foreach ($chain as $i => $certificate) {
            $parsed = openssl_x509_parse($certificate) ?: [];
            $this->line(sprintf(
                '  [%d] subject: %s | issuer: %s | names: %s | expires: %s | sha256: %s',
                $i,
                $this->name($parsed['subject'] ?? []),
                $this->name($parsed['issuer'] ?? []),
                $parsed['extensions']['subjectAltName'] ?? '-',
                isset($parsed['validTo_time_t']) ? gmdate('Y-m-d', $parsed['validTo_time_t']) : '?',
                (string) openssl_x509_fingerprint($certificate, 'sha256'),
            ));
        }

        // 2. Verified, exactly as the mailer will: the same options it adds.
        $added = SmtpTransportWithCaFile::sslOptions($config);
        $pinned = isset($added['peer_fingerprint']);
        $verified = $added + ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host];

        [$stream, $error] = $this->connect($host, $port, $implicitTls, $timeout, $verified);

        if ($stream === null) {
            $this->error('Verification: FAILED - '.$error.($pinned ? ' (pinned certificate did not match)' : ''));

            return self::FAILURE;
        }

        fclose($stream);
        $this->info($pinned ? 'Verification: OK (pinned certificate matched)' : 'Verification: OK');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $ssl
     * @return array{0: resource|null, 1: string}
     */
    private function connect(string $host, int $port, bool $implicitTls, float $timeout, array $ssl): array
    {
        $context = stream_context_create(['ssl' => $ssl + ['SNI_enabled' => true, 'peer_name' => $host]]);
        $target = ($implicitTls ? 'ssl://' : 'tcp://').$host.':'.$port;

        error_clear_last();
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            return [null, $this->lastError($errstr !== '' ? $errstr : 'connection or certificate verification failed')];
        }

        stream_set_timeout($stream, (int) ceil($timeout));

        if ($implicitTls) {
            return [$stream, ''];
        }

        // STARTTLS: greeting, EHLO, STARTTLS, then the handshake.
        $this->readReply($stream);
        fwrite($stream, "EHLO ethr-mail-probe\r\n");
        $this->readReply($stream);
        fwrite($stream, "STARTTLS\r\n");
        $reply = $this->readReply($stream);

        if (! str_starts_with($reply, '220')) {
            fclose($stream);

            return [null, 'server refused STARTTLS: '.trim($reply)];
        }

        error_clear_last();
        if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            fclose($stream);

            return [null, $this->lastError('TLS handshake failed')];
        }

        return [$stream, ''];
    }

    /** @param  resource  $stream */
    private function readReply($stream): string
    {
        $reply = '';
        while (($line = fgets($stream, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return $reply;
    }

    private function lastError(string $fallback): string
    {
        $message = error_get_last()['message'] ?? '';

        return trim(preg_replace('/\s+/', ' ', $message !== '' ? $message : $fallback) ?? $fallback);
    }

    /** @param  array<string, mixed>  $name */
    private function name(array $name): string
    {
        $parts = [];
        foreach (['CN', 'O'] as $key) {
            if (isset($name[$key])) {
                $parts[] = $key.'='.(is_array($name[$key]) ? implode(',', $name[$key]) : $name[$key]);
            }
        }

        return $parts === [] ? '-' : implode(', ', $parts);
    }
}

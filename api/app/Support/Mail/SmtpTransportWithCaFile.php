<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
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

        $caFile = (string) ($config['ca_file'] ?? '');
        $stream = $transport->getStream();

        if ($caFile !== '' && $stream instanceof SocketStream) {
            $path = str_starts_with($caFile, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $caFile) === 1
                ? $caFile
                : base_path($caFile);

            $stream->setStreamOptions(array_replace_recursive($stream->getStreamOptions(), [
                'ssl' => ['cafile' => $path],
            ]));
        }

        return $transport;
    }
}

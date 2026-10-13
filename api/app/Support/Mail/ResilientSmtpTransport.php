<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * SMTP that tries a verified connection first and the certificate pin second.
 *
 * The provider's SMTP certificate expired on 2026-02-11, so mail has gone out
 * on a SHA-256 pin of that certificate (MAIL_PEER_FINGERPRINT). The day the
 * provider installs its renewed certificate the pin stops matching — and with
 * the pin as the only path, every email would fail until someone edited the
 * host's .env. Now the verified connection is tried first: once the provider
 * renews, mail simply starts verifying, and a warning says the pin can come
 * out. While the certificate is still expired, a failed verified attempt is
 * remembered for half an hour so each send does not pay for a handshake that
 * cannot succeed.
 *
 * The pinned path is no weaker than before: it still refuses any certificate
 * but the pinned one. Without a pin configured this is the verified transport
 * alone, plus the health record (MailHealth).
 */
final class ResilientSmtpTransport implements TransportInterface
{
    private const VERIFIED_FAILING_KEY = 'ethr:mail:verified_failing';

    private const VERIFIED_RETRY_SECONDS = 1800;

    private const PIN_STALE_WARNED_KEY = 'ethr:mail:pin_stale_warned';

    public function __construct(
        private readonly TransportInterface $verified,
        private readonly ?TransportInterface $pinned = null,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->pinned === null) {
            return $this->attempt($this->verified, 'verified', $message, $envelope);
        }

        if (! Cache::has(self::VERIFIED_FAILING_KEY)) {
            try {
                $sent = $this->verified->send($message, $envelope);
                MailHealth::recordSent('verified');
                $this->warnPinNoLongerNeeded();

                return $sent;
            } catch (TransportExceptionInterface $e) {
                Cache::put(self::VERIFIED_FAILING_KEY, true, self::VERIFIED_RETRY_SECONDS);
                Log::info('Mail: verified TLS failed, using the pinned certificate', ['error' => $e->getMessage()]);
            }
        }

        return $this->attempt($this->pinned, 'pinned', $message, $envelope);
    }

    public function __toString(): string
    {
        return $this->pinned === null
            ? (string) $this->verified
            : sprintf('verified-then-pinned(%s)', $this->verified);
    }

    private function attempt(TransportInterface $transport, string $tls, RawMessage $message, ?Envelope $envelope): ?SentMessage
    {
        try {
            $sent = $transport->send($message, $envelope);
            MailHealth::recordSent($tls);

            return $sent;
        } catch (TransportExceptionInterface $e) {
            MailHealth::recordFailure();

            throw $e;
        }
    }

    /** Once a day at most: the provider's certificate verifies, so the pin is dead weight. */
    private function warnPinNoLongerNeeded(): void
    {
        if (Cache::add(self::PIN_STALE_WARNED_KEY, true, 86400)) {
            Log::warning('Mail: the server certificate now verifies normally. MAIL_PEER_FINGERPRINT can be removed from .env.');
        }
    }
}

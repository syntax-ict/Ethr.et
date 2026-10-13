<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RetryCriticalMail;
use App\Notifications\MfaResetNotification;
use App\Services\CurrentTenant;
use App\Support\Mail\CriticalMail;
use App\Support\Mail\MailHealth;
use App\Support\Mail\ResilientSmtpTransport;
use App\Support\Mail\SmtpTransportWithCaFile;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Mail that survives the provider renewing its certificate, and sign-in
 * emails that are retried instead of dropped (2026-10-14).
 */
function fakeTransport(bool $fails): TransportInterface
{
    return new class($fails) implements TransportInterface
    {
        public int $calls = 0;

        public function __construct(private readonly bool $fails) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $this->calls++;
            if ($this->fails) {
                throw new TransportException('certificate verify failed');
            }

            return new SentMessage($message, $envelope ?? new Envelope(new Address('a@ethr.test'), [new Address('b@ethr.test')]));
        }

        public function __toString(): string
        {
            return 'fake';
        }
    };
}

function anEmail(): Email
{
    return (new Email)->from('a@ethr.test')->to('b@ethr.test')->subject('x')->text('x');
}

beforeEach(fn () => Cache::flush());

describe('the SMTP transport', function () {
    it('falls back to the pinned certificate while the verified connection fails, and remembers it', function () {
        $verified = fakeTransport(fails: true);
        $pinned = fakeTransport(fails: false);
        $transport = new ResilientSmtpTransport($verified, $pinned);

        $transport->send(anEmail());
        $transport->send(anEmail());

        expect($verified->calls)->toBe(1);
        expect($pinned->calls)->toBe(2);
        expect(MailHealth::snapshot()['tls'])->toBe('pinned');
    });

    it('keeps mail flowing when the provider renews its certificate, and says the pin can go', function () {
        Log::spy();
        $verified = fakeTransport(fails: false);
        $pinned = fakeTransport(fails: true);

        (new ResilientSmtpTransport($verified, $pinned))->send(anEmail());

        expect($pinned->calls)->toBe(0);
        expect(MailHealth::snapshot()['tls'])->toBe('verified');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'MAIL_PEER_FINGERPRINT can be removed'))->once();
    });

    it('records a failure and still throws it when there is no fallback', function () {
        $transport = new ResilientSmtpTransport(fakeTransport(fails: true));

        expect(fn () => $transport->send(anEmail()))->toThrow(TransportException::class);
        expect(MailHealth::snapshot()['last_failed_at'])->not->toBeNull();
    });

    it('is the transport the smtp mailer gets, with the pin behind the verified path', function () {
        $config = ['transport' => 'smtp', 'host' => 'mail.example.com', 'port' => 587,
            'peer_sha256' => str_repeat('ab', 32)];

        $transport = SmtpTransportWithCaFile::create(app(), $config);

        expect($transport)->toBeInstanceOf(ResilientSmtpTransport::class);
        expect((string) $transport)->toStartWith('verified-then-pinned(');
    });

    it('shows mail health on /health without any error text', function () {
        MailHealth::recordSent('pinned');

        $this->getJson('/api/v1/health')
            ->assertJsonPath('mail_detail.tls', 'pinned')
            ->assertJsonStructure(['mail_detail' => ['last_sent_at', 'last_failed_at', 'tls']]);
    });
});

describe('sign-in emails', function () {
    it('queues an encrypted retry when the email cannot be sent now', function () {
        Queue::fake();
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::EMPLOYEE], $tenant);

        // A port nothing listens on: the real failure shape, not a mock.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1]);

        expect(CriticalMail::send($user, new MfaResetNotification))->toBeFalse();

        Queue::assertPushedOn('notifications', RetryCriticalMail::class, fn (RetryCriticalMail $job) => $job->userId === $user->id
            && $job->tenantId === $tenant->id);
        expect(new RetryCriticalMail($user->id, $tenant->id, new MfaResetNotification))->toBeInstanceOf(ShouldBeEncrypted::class);
    });

    it('delivers the retry to the user inside their own tenant', function () {
        Notification::fake();
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::EMPLOYEE], $tenant);
        app(CurrentTenant::class)->forget();

        app()->call([new RetryCriticalMail($user->id, $tenant->id, new MfaResetNotification), 'handle']);

        Notification::assertSentTo($user, MfaResetNotification::class);
    });

    it('still answers a password reset request when mail is down', function () {
        Queue::fake();
        $tenant = createTenant();
        createUser(['role' => UserRole::EMPLOYEE, 'email' => 'reset@ethr.test'], $tenant);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1]);

        $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/password/forgot", ['email' => 'reset@ethr.test'])
            ->assertSuccessful();

        Queue::assertPushed(RetryCriticalMail::class);
    });
});

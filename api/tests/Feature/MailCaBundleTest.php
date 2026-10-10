<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * MAIL_CA_FILE and the bundled GlobalSign chain.
 *
 * The production mail provider omits its intermediate certificate, so PHP
 * could not verify the SMTP server and every send failed with "certificate
 * verify failed" (2026-10-10). The bundle supplies that intermediate; these
 * tests keep it in the transport and keep it from expiring unnoticed.
 */
const MAIL_CA_BUNDLE = 'resources/certs/globalsign-gcc-r3-ev-tls-ca-2025.pem';

function smtpStreamOptions(): array
{
    Mail::purge('smtp');
    $transport = Mail::mailer('smtp')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    $stream = $transport->getStream();
    expect($stream)->toBeInstanceOf(SocketStream::class);

    return $stream->getStreamOptions();
}

beforeEach(function () {
    config()->set('mail.mailers.smtp.host', 'mail.example.test');
    config()->set('mail.mailers.smtp.port', 465);
});

it('verifies the SMTP server against MAIL_CA_FILE, resolved from api/', function () {
    config()->set('mail.mailers.smtp.ca_file', MAIL_CA_BUNDLE);

    $options = smtpStreamOptions();

    expect($options['ssl']['cafile'] ?? null)->toBe(base_path(MAIL_CA_BUNDLE))
        // The point is to verify, not to stop verifying.
        ->and($options['ssl']['verify_peer'] ?? true)->toBeTrue();
});

it('is the framework transport, untouched, when no CA file is set', function () {
    config()->set('mail.mailers.smtp.ca_file', null);

    expect(smtpStreamOptions()['ssl']['cafile'] ?? null)->toBeNull();
});

it('takes an absolute CA file path as given', function () {
    $absolute = base_path(MAIL_CA_BUNDLE);
    config()->set('mail.mailers.smtp.ca_file', $absolute);

    expect(smtpStreamOptions()['ssl']['cafile'])->toBe($absolute);
});

it('ships the GlobalSign intermediate and its root, neither within 30 days of expiry', function () {
    $pem = (string) file_get_contents(base_path(MAIL_CA_BUNDLE));
    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $blocks);

    expect($blocks[0])->toHaveCount(2);

    [$intermediate, $root] = array_map(fn (string $block) => openssl_x509_parse($block), $blocks[0]);

    expect($intermediate['subject']['CN'])->toBe('GlobalSign GCC R3 EV TLS CA 2025')
        ->and($intermediate['issuer']['OU'])->toBe('GlobalSign Root CA - R3')
        ->and($root['subject']['OU'])->toBe('GlobalSign Root CA - R3');

    // The intermediate really is signed by the root that ships with it.
    expect(openssl_x509_verify($blocks[0][0], $blocks[0][1]))->toBe(1);

    $soonest = min($intermediate['validTo_time_t'], $root['validTo_time_t']);
    expect($soonest)->toBeGreaterThan(now()->addDays(30)->timestamp,
        'The MAIL_CA_FILE bundle expires within 30 days. Fetch the current intermediate from the '
        .'mail server certificate\'s Authority Information Access URL, verify it against the system '
        .'trust store, and replace '.MAIL_CA_BUNDLE.'.');
});

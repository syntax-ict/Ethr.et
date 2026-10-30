<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\CurrentTenant;
use App\Support\OutboundHost;
use Illuminate\Support\Facades\Http;

/*
 * A webhook delivery connects to the addresses checked at send time. The host
 * was checked and then looked up again by the HTTP client, so a name whose DNS
 * answer changed between the two reached an address that was never checked.
 */

afterEach(fn () => OutboundHost::resolveUsing(null));

/** @param array<string, list<string>> $dns */
function pinningDns(array $dns): void
{
    OutboundHost::resolveUsing(fn (string $host): array => $dns[$host] ?? []);
}

/** @return array{0: DispatchWebhookJob, 1: WebhookDelivery} */
function pinningJob(string $url): array
{
    $tenant = createTenant();
    $webhook = Webhook::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'url' => $url,
        'events' => ['payroll.processed'],
        'secret' => 'shh',
        'is_active' => true,
    ]);
    $delivery = WebhookDelivery::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'webhook_id' => $webhook->id,
        'event' => 'payroll.processed',
        'payload' => ['event' => 'payroll.processed'],
        'attempt' => 0,
    ]);
    app(CurrentTenant::class)->forget();

    return [new DispatchWebhookJob($webhook->id, 'payroll.processed', ['event' => 'payroll.processed'], $delivery->id, $tenant->id), $delivery];
}

/** Fakes every request and returns a reference to the curl options of the last one sent. */
function pinningCapture(): ArrayObject
{
    $sent = new ArrayObject;
    Http::fake(function ($request, array $options) use ($sent) {
        $sent['curl'] = $options['curl'] ?? [];

        return Http::response('ok', 200);
    });

    return $sent;
}

it('connects to the address it checked', function (string $url, array $dns, string $pin) {
    pinningDns($dns);
    $sent = pinningCapture();
    [$job, $delivery] = pinningJob($url);

    $job->handle();

    expect($sent['curl'][CURLOPT_RESOLVE] ?? null)->toBe([$pin])
        ->and($delivery->fresh()->delivered_at)->not->toBeNull();
})->with([
    'https' => ['https://hooks.example.et/payroll', ['hooks.example.et' => ['93.184.216.34']], 'hooks.example.et:443:93.184.216.34'],
    'http' => ['http://hooks.example.et/payroll', ['hooks.example.et' => ['93.184.216.34']], 'hooks.example.et:80:93.184.216.34'],
    'own port' => ['https://hooks.example.et:8443/payroll', ['hooks.example.et' => ['93.184.216.34']], 'hooks.example.et:8443:93.184.216.34'],
    'IPv4 and IPv6' => [
        'https://hooks.example.et/payroll',
        ['hooks.example.et' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']],
        'hooks.example.et:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]',
    ],
]);

it('refuses a name with any internal answer, and sends nothing', function (array $addresses) {
    pinningDns(['hooks.example.et' => $addresses]);
    Http::fake();
    [$job, $delivery] = pinningJob('https://hooks.example.et/payroll');

    $job->handle();

    Http::assertNothingSent();
    expect($delivery->fresh()->response_body)->toContain('internal address');
})->with([
    'loopback' => [['127.0.0.1']],
    'one public, one private' => [['93.184.216.34', '10.0.0.5']],
    'metadata' => [['169.254.169.254']],
]);

it('retries a name that resolves to nothing rather than sending it unpinned', function () {
    pinningDns([]);
    Http::fake();
    [$job, $delivery] = pinningJob('https://hooks.example.et/payroll');

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'did not resolve');

    Http::assertNothingSent();
    expect($delivery->fresh()->response_body)->toBe('The webhook host did not resolve');
});

it('refuses a name curl would rewrite before matching the pin', function () {
    pinningDns(['hööks.example.et' => ['93.184.216.34']]);
    Http::fake();
    [$job] = pinningJob('https://hööks.example.et/payroll');

    $job->handle();

    Http::assertNothingSent();
});

it('sends to a public IP address as it is, with nothing to pin', function () {
    $sent = pinningCapture();
    [$job] = pinningJob('https://93.184.216.34/hooks');

    $job->handle();

    expect($sent['curl'])->not->toHaveKey(CURLOPT_RESOLVE);
});

it('looks the name up once per attempt', function () {
    $lookups = 0;
    OutboundHost::resolveUsing(function () use (&$lookups): array {
        $lookups++;

        return ['93.184.216.34'];
    });
    pinningCapture();
    [$job] = pinningJob('https://hooks.example.et/payroll');

    $job->handle();

    expect($lookups)->toBe(1);
});

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Rules\ExternalUrl;
use App\Services\CurrentTenant;
use App\Support\OutboundHost;
use App\Support\WebhookTargets;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * StoreWebhookRequest refused an internal URL; UpdateWebhookRequest did not,
 * and DispatchWebhookJob posted signed tenant data to whatever URL was stored.
 * So a webhook created with a public URL could be repointed at an internal
 * address (the host's own services, a metadata endpoint) with one PUT.
 */
function ssrfWebhook(object $tenant, string $url): Webhook
{
    return Webhook::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'url' => $url,
        'events' => ['payroll.processed'],
        'secret' => 'shh',
        'is_active' => true,
    ]);
}

test('a webhook cannot be repointed at an internal address', function (string $url) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $webhook = ssrfWebhook($tenant, 'https://hooks.example.et/payroll');

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks/{$webhook->public_id}", [
        'url' => $url,
    ])->assertStatus(422)->assertJsonValidationErrors('url');

    expect($webhook->fresh()->url)->toBe('https://hooks.example.et/payroll');
})->with([
    'loopback' => ['http://127.0.0.1:8080/admin'],
    'private network' => ['http://10.0.0.5/'],
    'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'IPv6 loopback' => ['http://[::1]/'],
    'localhost' => ['http://localhost/'],
    // curl expands these to 127.0.0.1; PHP's resolver did not, so they passed
    // as external (API/webhook end-to-end test, 2026-10-14, D8).
    'short IPv4 loopback' => ['http://127.1:8099/hook'],
    'octal IPv4 loopback' => ['http://0177.0.0.1/hook'],
    'carrier-grade NAT' => ['http://100.64.0.1/hook'],
    'NAT64 loopback' => ['http://[64:ff9b::7f00:1]/hook'],
]);

test('the local receiver switch opens private hosts on a developer machine only', function (string $env, bool $allowed) {
    config(['webhooks.allow_private_hosts' => true]);
    app()->detectEnvironment(fn () => $env);

    expect(WebhookTargets::refused('127.0.0.1'))->toBe(! $allowed)
        ->and(WebhookTargets::refused('10.0.0.5'))->toBe(! $allowed);
})->with([
    'local' => ['local', true],
    'testing' => ['testing', true],
    'production' => ['production', false],
    'staging' => ['staging', false],
]);

test('the switch is for webhooks: other outbound URLs stay refused', function () {
    config(['webhooks.allow_private_hosts' => true]);

    $webhookRule = Validator::make(['url' => 'http://127.0.0.1:8099/hook'], ['url' => [new ExternalUrl(webhookTarget: true)]]);
    $otherRule = Validator::make(['url' => 'http://127.0.0.1:8099/hook'], ['url' => [new ExternalUrl]]);

    expect($webhookRule->passes())->toBeTrue()
        ->and($otherRule->passes())->toBeFalse();
});

test('with the switch off, a host that is merely numeric is never external', function (string $host) {
    expect(OutboundHost::isInternal($host))->toBeTrue();
})->with(['127.1', '0177.0.0.1', '1.2.3', '2130706433', '0x7f000001', '0x7f.1', '10.1']);

test('a public address and a hostname made of hex letters are still external', function (string $host) {
    expect(OutboundHost::isInternal($host))->toBeFalse();
})->with(['93.184.216.34', '2a00:1450:4001:80b::200e', '::ffff:93.184.216.34', 'cafe.example']);

test('a delivery to an internal URL already stored is refused, not sent', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $tenant = createTenant();
    $webhook = ssrfWebhook($tenant, 'http://127.0.0.1:8080/admin');
    $delivery = WebhookDelivery::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'webhook_id' => $webhook->id,
        'event' => 'payroll.processed',
        'payload' => ['event' => 'payroll.processed'],
        'attempt' => 0,
    ]);
    app(CurrentTenant::class)->forget();

    (new DispatchWebhookJob($webhook->id, 'payroll.processed', ['event' => 'payroll.processed'], $delivery->id, $tenant->id))->handle();

    Http::assertNothingSent();
    expect(WebhookDelivery::withoutGlobalScopes()->findOrFail($delivery->id)->response_body)
        ->toContain('internal address');
});

test('a delivery to a public URL is still sent', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $tenant = createTenant();
    $webhook = ssrfWebhook($tenant, 'https://93.184.216.34/hooks');
    $delivery = WebhookDelivery::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'webhook_id' => $webhook->id,
        'event' => 'payroll.processed',
        'payload' => ['event' => 'payroll.processed'],
        'attempt' => 0,
    ]);
    app(CurrentTenant::class)->forget();

    (new DispatchWebhookJob($webhook->id, 'payroll.processed', ['event' => 'payroll.processed'], $delivery->id, $tenant->id))->handle();

    Http::assertSentCount(1);
});

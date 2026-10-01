<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Http;

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
]);

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

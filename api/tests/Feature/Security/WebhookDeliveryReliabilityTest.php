<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\DispatchWebhookJob;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\CurrentTenant;
use App\Services\Webhook\WebhookDispatcher;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Webhook delivery, as `docs/WEBHOOKS.md` (D14) found it: a 500 was never
 * retried, the 24 h backoff step was unreachable, failures were counted per
 * attempt, re-enabling kept the old count, redirects were followed past the
 * SSRF check, the signing secret was stored in plaintext, and the delivery and
 * tenant were named by numeric id. Audit N18.
 */
function reliableWebhook(Tenant $tenant, array $attributes = []): Webhook
{
    return Webhook::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://93.184.216.34/hooks',
        'events' => ['payroll.processed'],
        'secret' => 'shh',
        'is_active' => true,
        ...$attributes,
    ]);
}

/**
 * @return array{0: DispatchWebhookJob, 1: WebhookDelivery}
 */
function reliableJob(Tenant $tenant, Webhook $webhook, array $payload = ['event' => 'payroll.processed', 'data' => ['url' => 'https://a.test/x']]): array
{
    $delivery = WebhookDelivery::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'webhook_id' => $webhook->id,
        'event' => 'payroll.processed',
        'payload' => $payload,
        'attempt' => 0,
    ]);
    app(CurrentTenant::class)->forget();

    return [new DispatchWebhookJob($webhook->id, 'payroll.processed', $payload, $delivery->id, $tenant->id), $delivery];
}

test('the signature is over the exact bytes sent', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $tenant = createTenant();
    [$job] = reliableJob($tenant, reliableWebhook($tenant));

    $job->handle();

    Http::assertSent(fn (ClientRequest $request): bool => $request->header('X-ETHR-Signature')[0]
        === 'sha256='.hash_hmac('sha256', $request->body(), 'shh'));
});

test('the delivery header is the public id, the same on every retry', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $tenant = createTenant();
    [$job, $delivery] = reliableJob($tenant, reliableWebhook($tenant));

    $job->handle();

    $publicId = WebhookDelivery::withoutGlobalScopes()->whereKey($delivery->id)->value('public_id');
    expect($publicId)->toBeString()->toHaveLength(26);
    Http::assertSent(fn (ClientRequest $request): bool => $request->header('X-ETHR-Delivery')[0] === $publicId);
});

test('a server error is retried, and not yet counted against the webhook', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    $tenant = createTenant();
    $webhook = reliableWebhook($tenant);
    [$job] = reliableJob($tenant, $webhook);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect(Webhook::withoutGlobalScopes()->find($webhook->id)->failure_count)->toBe(0);
});

test('a client error is given up at once and counted once', function () {
    Http::fake(['*' => Http::response('gone', 404)]);
    $tenant = createTenant();
    $webhook = reliableWebhook($tenant);
    [$job, $delivery] = reliableJob($tenant, $webhook);

    $job->handle();

    expect(Webhook::withoutGlobalScopes()->find($webhook->id)->failure_count)->toBe(1)
        ->and(WebhookDelivery::withoutGlobalScopes()->find($delivery->id)->response_status)->toBe(404);
});

test('a redirect is not followed', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
    $tenant = createTenant();
    [$job] = reliableJob($tenant, reliableWebhook($tenant));

    $job->handle();

    Http::assertSentCount(1);
    Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '169.254.169.254'));
});

test('every backoff step is reachable', function () {
    $tenant = createTenant();
    [$job] = reliableJob($tenant, reliableWebhook($tenant));

    expect($job->tries)->toBe(count($job->backoff()) + 1);
});

test('exhausted retries count the delivery once, and the tenth disables the webhook', function () {
    $tenant = createTenant();
    $webhook = reliableWebhook($tenant, ['failure_count' => 9]);
    [$job] = reliableJob($tenant, $webhook);

    $job->failed(new RuntimeException('Webhook endpoint answered HTTP 503'));

    $fresh = Webhook::withoutGlobalScopes()->find($webhook->id);
    expect($fresh->failure_count)->toBe(10)
        ->and($fresh->is_active)->toBeFalse();
});

test('re-enabling a disabled webhook starts its failure count again', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $webhook = reliableWebhook($tenant, ['is_active' => false, 'failure_count' => 10]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks/{$webhook->public_id}", ['is_active' => true])
        ->assertOk();

    expect(Webhook::withoutGlobalScopes()->find($webhook->id)->failure_count)->toBe(0);
});

test('the signing secret is stored encrypted', function () {
    $tenant = createTenant();
    $webhook = reliableWebhook($tenant);

    expect(DB::table('webhooks')->where('id', $webhook->id)->value('secret'))->not->toBe('shh')
        ->and(Webhook::withoutGlobalScopes()->find($webhook->id)->secret)->toBe('shh');
});

test('the payload names the tenant by public id', function () {
    Queue::fake();
    $tenant = createTenant();
    $webhook = reliableWebhook($tenant);

    $delivery = app(WebhookDispatcher::class)->queue($webhook, 'payroll.processed', []);

    expect($delivery->payload['tenant_id'])->toBe($tenant->public_id);
});

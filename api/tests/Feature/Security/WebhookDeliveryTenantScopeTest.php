<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\CurrentTenant;
use App\Services\Webhook\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Webhook delivery rows, written from a context that has no tenant.
 *
 * `WebhookDispatcher::dispatch()` is called from `ProcessPayrollJob`, which is
 * queued. That job's own comment is explicit that "jobs carry no HTTP tenant
 * context, so the global scope would resolve to `whereRaw('0 = 1')`" — and it
 * scopes its own `PayrollRun` lookup by hand because of it. The webhook path
 * beneath it did not: `queue()` calls `WebhookDelivery::create()` without a
 * `tenant_id`, leaving `BelongsToTenant::creating` to supply one from ambient
 * state that a worker does not have.
 *
 * `webhook_deliveries.tenant_id` is `foreignId()->constrained()`, so it is NOT
 * NULL. And `DispatchesWebhooks::webhook()` wraps the call in `catch
 * (\Throwable)` with an empty body, so whatever happens is silent.
 *
 * These tests call the dispatcher directly, without that catch, so the
 * behaviour is visible rather than swallowed.
 */
function webhookTenant(string $subdomain): Tenant
{
    return Tenant::factory()->create(['subdomain' => $subdomain]);
}

function activeWebhook(Tenant $tenant, string $event = 'payroll.processed'): Webhook
{
    return Webhook::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'url' => 'https://example.et/hooks/'.$tenant->subdomain,
        'events' => [$event],
        'secret' => 'shh',
        'is_active' => true,
    ]);
}

beforeEach(function () {
    Queue::fake();
    app(CurrentTenant::class)->forget();
});

it('records a delivery when dispatched from a context with no tenant', function () {
    $tenant = webhookTenant('acme');
    activeWebhook($tenant);

    // Exactly the state a queue worker is in: nothing has resolved a tenant.
    app(CurrentTenant::class)->forget();

    app(WebhookDispatcher::class)->dispatch($tenant->id, 'payroll.processed', [
        'period' => '2026-09',
    ]);

    $delivery = WebhookDelivery::withoutGlobalScopes()->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->tenant_id)->toBe($tenant->id);
});

it('files the delivery against the webhook owner, not whoever was resolved last', function () {
    $owner = webhookTenant('owner');
    $other = webhookTenant('other');
    activeWebhook($owner);

    // A long-running worker holds `CurrentTenant` as a singleton, not a scoped
    // binding, so it is not flushed between jobs. Whatever the previous job set
    // is still resolved when the next one runs.
    app(CurrentTenant::class)->set($other);

    app(WebhookDispatcher::class)->dispatch($owner->id, 'payroll.processed', [
        'period' => '2026-09',
    ]);

    $delivery = WebhookDelivery::withoutGlobalScopes()->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->tenant_id)->toBe($owner->id)
        ->and($delivery->tenant_id)->not->toBe($other->id);
});

it('delivers from the worker, where no tenant is resolved', function () {
    // The read side of the same problem. `DispatchWebhookJob::handle()` looked
    // the delivery up through the global scope, so in a worker it resolved to
    // `0 = 1`, found nothing, and returned through the `! $delivery` guard --
    // indistinguishable from a delivery that had been deleted. The endpoint was
    // never called and nothing was logged.
    Http::fake(['*' => Http::response('ok', 200)]);

    $tenant = webhookTenant('acme');
    $webhook = activeWebhook($tenant);

    app(WebhookDispatcher::class)->dispatch($tenant->id, 'payroll.processed', [
        'period' => '2026-09',
    ]);

    $delivery = WebhookDelivery::withoutGlobalScopes()->firstOrFail();

    app(CurrentTenant::class)->forget();

    (new DispatchWebhookJob(
        $webhook->id,
        'payroll.processed',
        ['event' => 'payroll.processed'],
        $delivery->id,
    ))->handle();

    Http::assertSent(fn ($request) => $request->url() === $webhook->url);

    // Re-read without the scope: this assertion runs in the same no-tenant
    // context the job did, so `fresh()` would resolve to `0 = 1` and return null.
    $reloaded = WebhookDelivery::withoutGlobalScopes()->findOrFail($delivery->id);

    expect($reloaded->delivered_at)->not->toBeNull()
        ->and($reloaded->response_status)->toBe(200);
});

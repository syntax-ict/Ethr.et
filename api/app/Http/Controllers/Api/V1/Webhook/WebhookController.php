<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhook\StoreWebhookRequest;
use App\Http\Requests\Webhook\UpdateWebhookRequest;
use App\Models\AuditLog;
use App\Models\Webhook;
use App\Services\CurrentTenant;
use App\Services\Webhook\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('webhook.manage');

        $webhooks = Webhook::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Webhook $w) => [
                'public_id' => $w->public_id,
                'url' => $w->url,
                'events' => $w->events,
                'is_active' => $w->is_active,
                'failure_count' => $w->failure_count,
                'last_triggered_at' => $w->last_triggered_at,
                'created_at' => $w->created_at,
            ]);

        return response()->json(['webhooks' => $webhooks]);
    }

    public function store(StoreWebhookRequest $request): JsonResponse
    {
        Gate::authorize('webhook.manage');

        $tenant = app(CurrentTenant::class)->get();
        $secret = Str::random(32);

        $webhook = Webhook::create([
            'tenant_id' => $tenant->id,
            'url' => $request->validated('url'),
            'secret' => $secret,
            'events' => $request->validated('events'),
        ]);

        AuditLog::record('webhook.created', $webhook);

        return response()->json([
            'public_id' => $webhook->public_id,
            'url' => $webhook->url,
            'secret' => $secret,
            'events' => $webhook->events,
            'is_active' => $webhook->is_active,
            'created_at' => $webhook->created_at,
        ], 201);
    }

    public function update(UpdateWebhookRequest $request, Webhook $webhook): JsonResponse
    {
        Gate::authorize('webhook.manage');

        $webhook->update($request->validated());

        AuditLog::record('webhook.updated', $webhook);

        return response()->json([
            'public_id' => $webhook->public_id,
            'url' => $webhook->url,
            'events' => $webhook->events,
            'is_active' => $webhook->is_active,
        ]);
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        Gate::authorize('webhook.manage');

        AuditLog::record('webhook.deleted', $webhook);
        $webhook->delete();

        return response()->json(null, 204);
    }

    public function test(Webhook $webhook, WebhookDispatcher $dispatcher): JsonResponse
    {
        Gate::authorize('webhook.manage');

        $delivery = $dispatcher->sendTestEvent($webhook);

        return response()->json([
            'message' => 'Test event dispatched',
            'event' => 'test',
            'delivered_at' => $delivery->created_at,
        ]);
    }

    public function deliveries(Webhook $webhook): JsonResponse
    {
        Gate::authorize('webhook.manage');

        $deliveries = $webhook->deliveries()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($d) => [
                'event' => $d->event,
                'response_status' => $d->response_status,
                'attempt' => $d->attempt,
                'delivered_at' => $d->delivered_at,
                'created_at' => $d->created_at,
            ]);

        return response()->json(['deliveries' => $deliveries]);
    }
}

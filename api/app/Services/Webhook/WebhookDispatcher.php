<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Jobs\DispatchWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;

final class WebhookDispatcher
{
    public function dispatch(int $tenantId, string $event, array $data): void
    {
        $webhooks = Webhook::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Webhook $w) => in_array($event, $w->events, true));

        foreach ($webhooks as $webhook) {
            $this->queue($webhook, $event, $data);
        }
    }

    public function queue(Webhook $webhook, string $event, array $data): WebhookDelivery
    {
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'tenant_id' => $webhook->tenant_id,
            'data' => $data,
        ];

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $payload,
            'attempt' => 0,
        ]);

        DispatchWebhookJob::dispatch(
            $webhook->id,
            $event,
            $payload,
            $delivery->id,
        )->onQueue('default');

        return $delivery;
    }

    public function sendTestEvent(Webhook $webhook): WebhookDelivery
    {
        return $this->queue($webhook, 'test', [
            'message' => 'This is a test webhook delivery from ETHR.',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

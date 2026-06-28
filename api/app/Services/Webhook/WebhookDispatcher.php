<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Models\Webhook;
use App\Models\WebhookDelivery;

final class WebhookDispatcher
{
    public function dispatch(int $tenantId, string $event, array $data): void
    {
        $webhooks = Webhook::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Webhook $w) => in_array($event, $w->events));

        foreach ($webhooks as $webhook) {
            $this->deliver($webhook, $event, $data);
        }
    }

    public function deliver(Webhook $webhook, string $event, array $data): WebhookDelivery
    {
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'tenant_id' => $webhook->tenant_id,
            'data' => $data,
        ];

        $payloadJson = json_encode($payload);
        $signature = $webhook->sign($payloadJson);

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $payload,
            'attempt' => 1,
        ]);

        $webhook->update(['last_triggered_at' => now()]);

        return $delivery;
    }

    public function sendTestEvent(Webhook $webhook): WebhookDelivery
    {
        return $this->deliver($webhook, 'test', [
            'message' => 'This is a test webhook delivery',
        ]);
    }
}

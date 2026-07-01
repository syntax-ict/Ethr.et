<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    // Exponential backoff delays in seconds: 1m, 5m, 30m, 2h, 24h
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 86400];
    }

    public function __construct(
        private readonly int $webhookId,
        private readonly string $event,
        private readonly array $payload,
        private readonly int $deliveryId,
    ) {}

    public function handle(): void
    {
        $webhook = Webhook::withoutGlobalScopes()->find($this->webhookId);
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (! $webhook || ! $delivery) {
            return;
        }

        // Skip if webhook has been disabled between retries
        if (! $webhook->is_active) {
            $this->markFailed($delivery, null, 'Webhook disabled before delivery', 0);

            return;
        }

        $payloadJson = json_encode($this->payload);
        $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $webhook->secret);
        $attempt = $this->attempts();

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-ETHR-Signature' => $signature,
                    'X-ETHR-Event' => $this->event,
                    'X-ETHR-Delivery' => (string) $this->deliveryId,
                    'User-Agent' => 'ETHR-Webhooks/1.0',
                ])
                ->post($webhook->url, $this->payload);

            $statusCode = $response->status();
            $body = substr($response->body(), 0, 2000);

            if ($response->successful()) {
                // Success: reset failure count, record delivery
                $delivery->update([
                    'response_status' => $statusCode,
                    'response_body' => $body,
                    'attempt' => $attempt,
                    'delivered_at' => now(),
                ]);

                $webhook->update([
                    'failure_count' => 0,
                    'last_triggered_at' => now(),
                ]);
            } else {
                // HTTP error: increment failures
                $this->handleDeliveryFailure($webhook, $delivery, $statusCode, $body, $attempt);
            }
        } catch (Throwable $e) {
            Log::warning('Webhook delivery failed', [
                'webhook_id' => $this->webhookId,
                'event' => $this->event,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ]);

            $this->handleDeliveryFailure($webhook, $delivery, null, $e->getMessage(), $attempt);

            // Re-throw so the queue retries with backoff
            throw $e;
        }
    }

    private function handleDeliveryFailure(
        Webhook $webhook,
        WebhookDelivery $delivery,
        ?int $statusCode,
        string $body,
        int $attempt,
    ): void {
        $newFailureCount = $webhook->failure_count + 1;

        $delivery->update([
            'response_status' => $statusCode,
            'response_body' => $body,
            'attempt' => $attempt,
        ]);

        $webhook->update(['failure_count' => $newFailureCount]);

        // Auto-disable after 10 consecutive failures
        if ($newFailureCount >= 10) {
            $webhook->update(['is_active' => false]);

            Log::warning('Webhook auto-disabled after 10 consecutive failures', [
                'webhook_id' => $this->webhookId,
                'url' => $webhook->url,
            ]);
        }
    }

    private function markFailed(WebhookDelivery $delivery, ?int $status, string $body, int $attempt): void
    {
        $delivery->update([
            'response_status' => $status,
            'response_body' => $body,
            'attempt' => $attempt,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        // Final attempt exhausted — record it but don't re-queue
        Log::error('Webhook delivery permanently failed', [
            'webhook_id' => $this->webhookId,
            'delivery_id' => $this->deliveryId,
            'event' => $this->event,
            'error' => $exception->getMessage(),
        ]);

        $delivery = WebhookDelivery::find($this->deliveryId);
        if ($delivery && ! $delivery->delivered_at) {
            $delivery->update([
                'response_body' => 'Permanently failed: '.$exception->getMessage(),
                'attempt' => $this->attempts(),
            ]);
        }
    }
}

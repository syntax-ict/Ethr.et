<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\OutboundHost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // One more than the backoff entries: Laravel takes the delay before
    // retry n from entry n - 1, so with 5 tries the 24 h step was never
    // reached and the last attempt came about two hours in (audit N18).
    public int $tries = 6;

    public int $timeout = 30;

    // Exponential backoff delays in seconds: 1m, 5m, 30m, 2h, 24h
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 86400];
    }

    /**
     * `$tenantId` is required, as of the §11j drain.
     *
     * It was nullable with a default from §11g until 2026-09-23, so that jobs
     * serialized before that deploy still unserialized — `unserialize()` does
     * not run the constructor, and an absent property takes the declared
     * default instead of staying uninitialized. That window is closed: the
     * queue is drained before this deploys (`docs/DEPLOYMENT.md` → *Draining
     * the queue before an upgrade*), so no payload without a tenant id can
     * still be in flight, and a conditional predicate is not a predicate the
     * query states.
     */
    public function __construct(
        private readonly int $webhookId,
        private readonly string $event,
        private readonly array $payload,
        private readonly int $deliveryId,
        private readonly int $tenantId,
    ) {}

    public function handle(): void
    {
        // States `tenant_id` itself rather than resting on the dispatcher alone.
        // The id arrives in a queue payload; if it were ever wrong — a bug, a
        // replay, a hand-requeued job — an unscoped `find()` would hand back
        // another tenant's webhook and this job would sign and POST their data
        // to it. With the predicate the mismatch yields null and the guard
        // below returns.
        $webhook = Webhook::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($this->webhookId);

        // Scoped by hand for the same reason the line above is: a queue worker
        // resolves no tenant, so `BelongsToTenant` would apply `0 = 1` and this
        // would silently find nothing -- and the guard below would return as if
        // the delivery had been deleted. The tenant predicate is re-applied by
        // deriving from the webhook, which is itself tenant-owned.
        $delivery = $webhook === null
            ? null
            : WebhookDelivery::withoutGlobalScopes()
                ->where('tenant_id', $webhook->tenant_id)
                ->where('webhook_id', $webhook->id)
                ->find($this->deliveryId);

        if (! $webhook || ! $delivery) {
            return;
        }

        // Skip if webhook has been disabled between retries
        if (! $webhook->is_active) {
            $this->markFailed($delivery, null, 'Webhook disabled before delivery', 0);

            return;
        }

        // Re-checked at send time, not only when the URL was saved: the update
        // request did not apply ExternalUrl until 2026-10-01, so a webhook
        // created with a public URL could be repointed at an internal one, and
        // rows saved that way are still in the database. A host that now
        // resolves inside the network is refused the same way. Not retried —
        // retrying cannot make an internal address external.
        $host = parse_url((string) $webhook->url, PHP_URL_HOST);
        if (! is_string($host) || OutboundHost::isInternal(trim($host, '[]'))) {
            $this->markFailed($delivery, null, 'Refused: the webhook URL points to an internal address', 0);

            return;
        }

        // The signed bytes are the bytes sent. Handing the client the array
        // let it encode the payload a second time; the signature held only
        // because both encodings happened to use the same flags.
        $payloadJson = (string) json_encode($this->payload);
        $signature = 'sha256='.$webhook->sign($payloadJson);
        $attempt = $this->attempts();

        try {
            // Redirects are not followed: the host was checked above, and a 3xx
            // can point anywhere, including inside the network (audit N18).
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withHeaders([
                    'X-ETHR-Signature' => $signature,
                    'X-ETHR-Event' => $this->event,
                    'X-ETHR-Delivery' => (string) $delivery->public_id,
                    'User-Agent' => 'ETHR-Webhooks/1.0',
                ])
                ->withBody($payloadJson, 'application/json')
                ->post((string) $webhook->url);
        } catch (Throwable $e) {
            Log::warning('Webhook delivery failed', [
                'webhook_id' => $this->webhookId,
                'event' => $this->event,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ]);

            $this->recordAttempt($delivery, null, $e->getMessage(), $attempt);

            // Retried with backoff; failed() counts the delivery once the
            // retries run out.
            throw $e;
        }

        $statusCode = $response->status();
        $body = substr($response->body(), 0, 2000);

        if ($response->successful()) {
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

            return;
        }

        $this->recordAttempt($delivery, $statusCode, $body, $attempt);

        // Only network errors were retried; an endpoint answering 500 got one
        // attempt. A server error, a timeout or a rate limit is worth trying
        // again, so it is thrown for the queue to retry with backoff.
        if ($statusCode === 408 || $statusCode === 425 || $statusCode === 429 || $statusCode >= 500) {
            throw new RuntimeException("Webhook endpoint answered HTTP {$statusCode}");
        }

        // Any other 4xx, or a redirect: the same request gets the same answer.
        // Given up now, and counted once.
        $this->countFailedDelivery($webhook);
    }

    private function recordAttempt(WebhookDelivery $delivery, ?int $statusCode, string $body, int $attempt): void
    {
        $delivery->update([
            'response_status' => $statusCode,
            'response_body' => $body,
            'attempt' => $attempt,
        ]);
    }

    /**
     * One failed delivery, not one failed attempt: counted per attempt, a
     * delivery retried six times would disable its webhook before the second
     * one finished. Ten consecutive failed deliveries disable it.
     */
    private function countFailedDelivery(Webhook $webhook): void
    {
        $newFailureCount = $webhook->failure_count + 1;

        $webhook->update(['failure_count' => $newFailureCount]);

        if ($newFailureCount >= 10) {
            $webhook->update(['is_active' => false]);

            Log::warning('Webhook auto-disabled after 10 consecutive failed deliveries', [
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

        // Same scoping as handle(), and for the same reason: `failed()` runs on
        // the worker with no tenant resolved, so a plain find() resolves to
        // `0 = 1` and the permanent-failure record is never written to the row
        // the tenant actually reads in the deliveries dialog. The log line fired
        // and the delivery kept whatever transient state it had.
        // States `tenant_id` unconditionally, like handle(). Root CLAUDE.md
        // recorded the asymmetry this removes: handle() stated tenant_id while
        // failed() derived from webhook_id alone. Both held — webhook_id is
        // itself tenant-owned — but two halves of one class disagreeing about
        // how much to state is the shape a later defect takes.
        $delivery = WebhookDelivery::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('webhook_id', $this->webhookId)
            ->find($this->deliveryId);

        if ($delivery && ! $delivery->delivered_at) {
            $delivery->update([
                'response_body' => 'Permanently failed: '.$exception->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Retries exhausted: this is where a retried delivery is counted.
            // Same predicate as handle(), for the same reason.
            $webhook = Webhook::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->find($this->webhookId);

            if ($webhook !== null) {
                $this->countFailedDelivery($webhook);
            }
        }
    }
}

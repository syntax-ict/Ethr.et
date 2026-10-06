<?php

declare(strict_types=1);

namespace App\Traits;

use App\Services\Webhook\WebhookDispatcher;
use Illuminate\Support\Facades\Log;

trait DispatchesWebhooks
{
    protected function webhook(int $tenantId, string $event, array $data): void
    {
        try {
            app(WebhookDispatcher::class)->dispatch($tenantId, $event, $data);
        } catch (\Throwable $e) {
            // Still never fails the business operation -- a payroll run must not
            // roll back because a customer's endpoint is unreachable. But the
            // empty catch that used to be here swallowed everything, including
            // an integrity violation that meant `payroll.processed` could never
            // be delivered from a queued context at all. Nothing logged it, so
            // nothing could have noticed.
            Log::error('Webhook dispatch failed and was suppressed', [
                'tenant_id' => $tenantId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

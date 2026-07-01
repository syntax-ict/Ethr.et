<?php

declare(strict_types=1);

namespace App\Traits;

use App\Services\Webhook\WebhookDispatcher;

trait DispatchesWebhooks
{
    protected function webhook(int $tenantId, string $event, array $data): void
    {
        try {
            app(WebhookDispatcher::class)->dispatch($tenantId, $event, $data);
        } catch (\Throwable) {
            // Never let webhook dispatch fail a business operation
        }
    }
}

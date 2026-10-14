<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a webhook may be sent to a host. Internal and private hosts are
 * refused (OutboundHost), except on a developer's machine with
 * WEBHOOK_ALLOW_PRIVATE_HOSTS set — so a local receiver can be tested end to
 * end. The switch is ignored outside the `local` and `testing` environments.
 */
final class WebhookTargets
{
    public static function privateHostsAllowed(): bool
    {
        return (bool) config('webhooks.allow_private_hosts', false)
            && app()->environment(['local', 'testing']);
    }

    public static function refused(string $host): bool
    {
        return ! self::privateHostsAllowed() && OutboundHost::isInternal($host);
    }
}

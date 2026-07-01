<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
 * Personal notification channel — each authenticated user subscribes to their own channel.
 * Channel name: user.{userId} where userId is the user's numeric primary key.
 */
Broadcast::channel('user.{publicId}', function ($user, string $publicId): bool {
    return $user->public_id === $publicId;
});

/*
 * Tenant-wide announcement channel.
 * Any authenticated user belonging to the tenant may subscribe.
 */
Broadcast::channel('tenant.{tenantId}', function ($user, string $tenantId): bool {
    return (string) $user->tenant_id === $tenantId;
});

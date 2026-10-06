<?php

declare(strict_types=1);

use App\Models\Device;
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

/*
 * Device-specific channel for real-time device status updates.
 *
 * The ownership check is not redundant with the permission check. `devices.view`
 * says the user may look at devices; it says nothing about *whose*. Without the
 * tenant clause any holder of that permission in any tenant could subscribe to
 * another tenant's device by public_id and watch its status events — a leak that
 * travels over the WebSocket and so bypasses every HTTP-layer control, including
 * EnsureUserBelongsToTenant.
 *
 * withoutGlobalScopes() is deliberate: broadcast authorisation runs outside the
 * HTTP middleware stack, so CurrentTenant is not resolved here and the global
 * scope would match nothing. The tenant comparison is therefore explicit.
 */
Broadcast::channel('device.{devicePublicId}', function ($user, string $devicePublicId): bool {
    // `device.view`, not `devices.view`. The plural spelling matched no
    // permission in PermissionSeeder, so hasPermission() was always false and
    // this channel silently denied everyone except super admins — device status
    // never reached a single dashboard. It also meant the missing tenant check
    // below was not exploitable, which is why nobody noticed either half.
    if (! $user->hasPermission('device.view')) {
        return false;
    }

    return Device::withoutGlobalScopes()
        ->where('public_id', $devicePublicId)
        ->where('tenant_id', $user->tenant_id)
        ->exists();
});

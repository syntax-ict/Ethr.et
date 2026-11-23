<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether this deployment pushes live updates over a websocket. Reverb is the
 * only broadcaster this app uses, and production runs `BROADCAST_CONNECTION=null`,
 * so the broadcast channel and the event broadcast job are used only when this is
 * true. The other connections `config/broadcasting.php` defines (`log`, `pusher`,
 * `ably`) count as off: nothing here listens on them.
 */
final class LiveUpdates
{
    public static function enabled(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }
}

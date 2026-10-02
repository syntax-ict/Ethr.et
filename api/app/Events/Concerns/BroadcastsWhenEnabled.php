<?php

declare(strict_types=1);

namespace App\Events\Concerns;

/**
 * Laravel queues a BroadcastEvent job for every ShouldBroadcast event whatever
 * the driver, and production runs BROADCAST_CONNECTION=null — so without this,
 * every attendance punch queued a job that broadcast to nothing. The
 * notifications already gate their `broadcast` channel the same way.
 */
trait BroadcastsWhenEnabled
{
    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') !== 'null';
    }
}

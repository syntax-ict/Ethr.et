<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Accounts that follow their job title have started waiting for someone who
 * may give them the role it names (`access:watch-held-titles`). Carries ids,
 * not models: the listener runs on a worker with no organisation resolved.
 * The tenant is a required int, stated by the sweep that dispatched it.
 */
class TitleAccessHeld
{
    use Dispatchable, SerializesModels;

    /** @param  list<int>  $userIds  The accounts that were not waiting at the last sweep. */
    public function __construct(
        public readonly int $tenantId,
        public readonly array $userIds,
    ) {}
}

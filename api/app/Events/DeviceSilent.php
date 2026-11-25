<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A terminal that only sends to ETHR has gone quiet when it should not have
 * (DeviceSilence). Carries values, not the device: the listener runs on a
 * worker with no organisation resolved, where the device's branch could not be
 * read through the tenant scope. The tenant is a required int, stated by the
 * sweep that dispatched it, and not broadcast, as DeviceSyncFailed is not.
 */
class DeviceSilent
{
    use Dispatchable, SerializesModels;

    /** @param  'adms'|'push'  $kind */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $devicePublicId,
        public readonly string $deviceName,
        public readonly ?string $branchName,
        public readonly string $kind,
        public readonly string $since,
    ) {}
}

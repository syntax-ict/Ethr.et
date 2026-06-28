<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceOffline
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Device $device,
    ) {}
}

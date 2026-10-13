<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * pending → sent → done, or failed.
 *
 * `sent` exists for devices that collect their commands (ZKTeco ADMS polls for
 * them and confirms separately). A device ETHR calls directly goes from
 * pending to done or failed in one step. `cancelled` is a pending command a
 * newer one replaced — a second edit to the same person before the device
 * picked up the first.
 */
enum DeviceCommandStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case DONE = 'done';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}

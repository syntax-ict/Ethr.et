<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\DeviceCommandRejected;
use App\Models\Device;
use App\Models\DeviceCommand;

/**
 * An adapter ETHR can call to carry out a command on the device itself.
 *
 * Only for devices the server can reach. On shared hosting that is a device
 * with a public address; a device on a customer's LAN has to collect its
 * commands instead (ZKTeco ADMS) or go through a bridge.
 */
interface AcceptsDeviceCommands
{
    /**
     * @throws DeviceCommandRejected when the device answered and refused —
     *                               counts as an attempt
     *
     * Any other exception means the device was not reached; the command stays
     * pending for the next delivery.
     */
    public function execute(Device $device, DeviceCommand $command): void;
}

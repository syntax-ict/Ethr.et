<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * An adapter whose device comes to ETHR for its commands rather than being
 * called — a ZKTeco checking in over ADMS. ETHR cannot reach such a device,
 * which is exactly why it can still be managed from shared hosting: the
 * commands wait in `device_commands` until it asks.
 */
interface CollectsDeviceCommands {}

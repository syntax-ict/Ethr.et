<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A device adapter that has nothing to connect to: the device sends its
 * punches to ETHR and that is the whole of the conversation.
 *
 * The scheduled pull skips such a device. Pulling it found nothing, moved
 * `last_sync_at` to now, and so made a silent terminal look as if it had just
 * been heard from.
 */
interface ReceivesPunchesOnly {}

<?php

declare(strict_types=1);

namespace App\Services\Attendance\Device;

use RuntimeException;

/** A passkey response that does not verify: wrong origin, challenge, key or signature. */
final class PasskeyRejected extends RuntimeException {}

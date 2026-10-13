<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The device was reached and refused the command. */
final class DeviceCommandRejected extends RuntimeException {}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A check-out with no open check-in to close.
 *
 * Extends RuntimeException so the callers that already catch that (mobile and
 * QR check-in, the device webhooks) keep working; bootstrap/app.php renders it
 * as a 422 for every other HTTP caller. It used to be a bare RuntimeException
 * that the web, kiosk, manual and offline-sync endpoints did not catch, so a
 * forgotten check-in answered 500.
 */
final class NoOpenCheckIn extends RuntimeException {}

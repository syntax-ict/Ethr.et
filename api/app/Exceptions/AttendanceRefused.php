<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A punch the attendance engine will not record: the method is disabled, the
 * device is outside the geofence, there is no open check-in to close, or the
 * employee has left.
 *
 * The caller's mistake, never a server fault — bootstrap/app.php renders it as
 * a 422 for every endpoint. These were bare RuntimeExceptions that only the
 * mobile and QR endpoints caught, so the web, kiosk, manual and offline-sync
 * endpoints answered 500. A RuntimeException still, so those catches (and the
 * device webhooks' per-event catch) keep working.
 */
class AttendanceRefused extends RuntimeException {}

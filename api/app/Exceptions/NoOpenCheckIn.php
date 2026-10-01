<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A check-out with no open check-in to close.
 *
 * One of the AttendanceRefused cases (rendered as a 422). It used to be a
 * bare RuntimeException that the web, kiosk, manual and offline-sync endpoints
 * did not catch, so a forgotten check-in answered 500.
 */
final class NoOpenCheckIn extends AttendanceRefused {}

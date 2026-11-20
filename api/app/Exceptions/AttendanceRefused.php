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
class AttendanceRefused extends RuntimeException
{
    /**
     * `$permanent` says the refusal belongs to the punch itself and nothing
     * HR changes later can turn it into a yes (the employee has left, the retry
     * key is another person's). A punch queued offline is dropped for good only
     * on a permanent refusal. Every other refusal depends on settings or state
     * read when the punch reaches the server, so it can pass: a method switched
     * off for an hour, a phone approved the next day, a check-in that has not
     * landed yet. The offline window ends those after its own limit.
     */
    public function __construct(string $message = '', public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}

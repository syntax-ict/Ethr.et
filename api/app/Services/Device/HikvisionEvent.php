<?php

declare(strict_types=1);

namespace App\Services\Device;

/**
 * Reads one Hikvision access-control event — from an ISAPI `AcsEvent` search
 * (pull) or an `AccessControllerEvent` notification (push) — the same way on
 * both paths.
 *
 * Direction comes from `attendanceStatus`, the field a terminal sets from its
 * attendance mode or the check-in/check-out key the person pressed. Until
 * 2026-10-14 the push path recorded every event as a check-in and the pull
 * path read `eventType` 1/2, a field these events do not carry, so an evening
 * punch opened a second record instead of closing the morning one. A terminal
 * in plain access mode reports `undefined`; that is `auto` — the engine closes
 * the day's open record or opens one (AttendanceEngine::record).
 *
 * Only events that name an enrolled person count. `employeeNoString` is the
 * device's user id, set only when the terminal matched someone it has
 * enrolled; a bare `cardNo` arrives on unregistered-card and other failed
 * events, so it is not treated as a punch.
 */
final class HikvisionEvent
{
    private const IN = ['checkin', 'breakin', 'overtimein'];

    private const OUT = ['checkout', 'breakout', 'overtimeout'];

    /** The enrolled person's device user id, or null when the event names nobody. */
    public static function badge(array $event): ?string
    {
        $badge = $event['employeeNoString'] ?? $event['employeeNo'] ?? null;

        if (! is_scalar($badge)) {
            return null;
        }

        $badge = trim((string) $badge);

        return $badge === '' ? null : $badge;
    }

    /** `check_in`, `check_out`, or `auto` when the terminal does not say. */
    public static function direction(array $event): string
    {
        $status = strtolower((string) ($event['attendanceStatus'] ?? ''));

        return match (true) {
            in_array($status, self::IN, true) => 'check_in',
            in_array($status, self::OUT, true) => 'check_out',
            default => 'auto',
        };
    }

    /**
     * The access-control event inside a notification. JSON notifications nest
     * it under `AccessControllerEvent`; multipart ones (most firmware, because
     * they attach the face snapshot) carry the same JSON as a form field,
     * usually `event_log`. The time is on the envelope (`dateTime`), not the
     * inner event, and is copied in when the inner event has none.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null null for heartbeats and anything that is not an access event
     */
    public static function fromNotification(array $payload): ?array
    {
        foreach (['event_log', 'AccessControllerEvent', 'eventLog'] as $field) {
            if (is_string($payload[$field] ?? null)) {
                $decoded = json_decode($payload[$field], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                    break;
                }
            }
        }

        $event = $payload['AccessControllerEvent'] ?? null;
        if (! is_array($event)) {
            return null;
        }

        $event['time'] ??= $payload['dateTime'] ?? $event['dateTime'] ?? null;

        return $event;
    }
}

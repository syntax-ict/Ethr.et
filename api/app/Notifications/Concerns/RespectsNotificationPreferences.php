<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\NotificationPreference;

/**
 * Makes `via()` consult the per-user preference matrix.
 *
 * The matrix has been settable since the notification-preferences endpoint shipped,
 * and the settings page has always rendered the toggles — but every notification
 * hard-coded its own channel list, so nothing ever read a stored row. Turning off
 * "email me about payslips" changed a database row and nothing else.
 *
 * Channel names differ between the two vocabularies: the API speaks in
 * `in_app` / `email` / `sms`, Laravel in `database` / `broadcast` / `mail`. The
 * mapping lives here so consumers keep declaring Laravel channels.
 *
 * `in_app` is deliberately not honoured as an opt-out. The controller already
 * refuses to store `in_app => false` (it is the record of what happened, not a
 * delivery preference), so treating it as switchable here would only create a
 * second, disagreeing source of truth.
 */
trait RespectsNotificationPreferences
{
    /**
     * The preference key this notification is filed under. Must be one of
     * `NotificationPreferencesController::NOTIFICATION_TYPES`, otherwise the
     * notification has no toggle and every channel stays enabled.
     */
    abstract protected function preferenceType(): string;

    /**
     * Filter a default channel list down to what the notifiable has opted into.
     *
     * @param  array<int, string>  $channels  Laravel channel names
     * @return array<int, string>
     */
    protected function filterChannels(object $notifiable, array $channels): array
    {
        $userId = $notifiable->getKey();

        if (! is_int($userId) && ! is_string($userId)) {
            return $channels;
        }

        // `withoutGlobalScopes()` is load-bearing, not a shortcut. NotificationPreference
        // is BelongsToTenant, and a notification is frequently delivered with no
        // CurrentTenant bound — a queue worker picking up a queued notification has no
        // request to resolve the tenant from. Under the global scope that lookup returns
        // zero rows, which is indistinguishable from "user has no preferences", so every
        // opt-out would be silently ignored on exactly the delivery path that matters.
        //
        // Dropping the scope is safe here because `user_id` is strictly narrower than
        // `tenant_id`: a user belongs to exactly one tenant, so filtering by the user
        // cannot reach another tenant's rows.
        $stored = NotificationPreference::query()
            ->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('notification_type', $this->preferenceType())
            ->pluck('enabled', 'channel');

        return array_values(array_filter($channels, function (string $channel) use ($stored): bool {
            $preferenceChannel = match ($channel) {
                'mail' => 'email',
                'vonage', 'sms' => 'sms',
                default => null,
            };

            // database/broadcast — the in-app record. Always delivered.
            if ($preferenceChannel === null) {
                return true;
            }

            // No stored row means the user has never touched this toggle, so the
            // controller's defaults apply: email on, SMS off.
            if (! $stored->has($preferenceChannel)) {
                return $preferenceChannel !== 'sms';
            }

            return (bool) $stored[$preferenceChannel];
        }));
    }
}

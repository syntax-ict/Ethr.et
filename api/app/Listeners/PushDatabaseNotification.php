<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Feature;
use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Features\FeatureGate;
use App\Services\Push\WebPushSender;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Every in-app notification also reaches the user's subscribed phones and
 * browsers as a web push: an approval waiting, a missing punch, a payslip.
 * One listener rather than a channel on each notification, so a notification
 * added later reaches phones without anyone remembering to wire it.
 *
 * Only the database channel triggers it (each notification is stored once,
 * whatever else it is sent by), only for users who subscribed, and only
 * while push is configured. The text is the notification's own message.
 */
class PushDatabaseNotification
{
    public function __construct(
        private readonly WebPushSender $sender,
        private readonly FeatureGate $features,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $this->sender->enabled()) {
            return;
        }

        $user = $event->notifiable;
        if ($user->tenant_id === null) {
            return;
        }

        // Dropped while phone notifications are switched off: a push about a
        // punch an hour ago is worth nothing (design §6, posture `drop`). The
        // notification itself is still on the bell.
        $tenant = Tenant::query()->find($user->tenant_id);
        if ($tenant === null || ! $this->features->allows($tenant, Feature::WebPush)) {
            return;
        }

        $hasSubscription = PushSubscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->exists();
        if (! $hasSubscription) {
            return;
        }

        $data = method_exists($event->notification, 'toArray') ? $event->notification->toArray($user) : [];
        $body = $data['message'] ?? $data['title'] ?? $data['body'] ?? null;
        if (! is_string($body) || $body === '') {
            return;
        }

        SendWebPush::dispatch($user->tenant_id, $user->id, [
            'title' => is_string($data['title'] ?? null) && $data['title'] !== $body ? $data['title'] : 'ETHR',
            'body' => $body,
            'url' => is_string($data['url'] ?? null) ? $data['url'] : '/dashboard',
            'tag' => class_basename($event->notification),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant;
use App\Models\User;
use App\Support\MailBrand;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the mail layout which organisation the email now being built is from.
 *
 * The organisation is read from the recipient's own row, never from the
 * notification. A recipient with no organisation (an anonymous notifiable, a
 * platform account) gets ETHR's own brand, and so does any failure here: the
 * email still goes out.
 */
class BrandMail
{
    public function handle(NotificationSending $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $brand = app(MailBrand::class);

        try {
            $notifiable = $event->notifiable;
            $tenant = $this->tenantOf($notifiable);

            $locale = $event->notification->locale
                ?? ($notifiable instanceof HasLocalePreference ? $notifiable->preferredLocale() : null)
                ?? app()->getLocale();

            $brand->use($tenant, $locale);
        } catch (Throwable $e) {
            $brand->use(null, 'en');
            Log::warning('Mail brand could not be resolved; sending under the platform brand', [
                'notification' => $event->notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The organisation behind the recipient: a user's own row, or, for an address
     * on a report or digest (an anonymous notifiable), the `tenant` route the job
     * set from its own tenant-owned schedule. Never read from the notification.
     */
    private function tenantOf(mixed $notifiable): ?Tenant
    {
        $tenantId = match (true) {
            $notifiable instanceof User => $notifiable->tenant_id,
            $notifiable instanceof AnonymousNotifiable => $notifiable->routeNotificationFor('tenant'),
            default => null,
        };

        return is_int($tenantId) ? Tenant::query()->find($tenantId) : null;
    }
}

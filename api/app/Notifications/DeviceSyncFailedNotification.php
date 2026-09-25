<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Branch;
use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The sibling of `DeviceOfflineNotification`, for the state it does not cover.
 *
 * The reason IS included here, unlike the scheduled-report and digest failure
 * notices which deliberately omit it. The audience is the difference: those go
 * to free-text email addresses on a schedule, this goes to `tenant_admin` and
 * `hr_admin` users of the device's own tenant, who are already entitled to the
 * device's configuration. Without the reason the alert says only "something
 * broke", and the whole value of separating `error` from `offline` is telling
 * an admin which kind of problem they have.
 */
class DeviceSyncFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Device $device,
        private readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * The branch is narrowed rather than read through `?->`, deliberately.
     *
     * `DeviceOfflineNotification` writes `$this->device->branch?->name ?? '...'`
     * and passes PHPStan only because both of its occurrences sit in
     * `phpstan-baseline.neon`. Relations in this codebase carry no PHPStan
     * generics, so `$device->branch` resolves statically to a bare `Model`:
     * reading `->name` off it is `property.notFound`, and the `?->` is
     * `nullsafe.neverNull` because a `BelongsTo` is not statically nullable.
     * Two errors, both baselined there.
     *
     * Copying the line would have meant copying the baseline entry, which is
     * how a baseline grows into a permission. `instanceof Branch` satisfies the
     * analyser outright — `QrCodeService` already reads `$branch->name` off a
     * narrowed `Branch` with no complaint — and it is the same shape
     * `SendsNotifications::userOf()` uses for exactly this reason.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'device_id' => $this->device->public_id,
            'device_name' => $this->device->name,
            'location' => $this->location(),
            'reason' => $this->reason,
            'message' => __('notification.device_sync_failed_body', [
                'name' => $this->device->name,
                'reason' => $this->reason,
            ]),
        ];
    }

    private function location(): string
    {
        $branch = $this->device->branch;

        return $branch instanceof Branch ? (string) $branch->name : 'Unknown';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notification.device_sync_failed_subject', ['name' => $this->device->name]))
            ->line(__('notification.device_sync_failed_body', [
                'name' => $this->device->name,
                'reason' => $this->reason,
            ]))
            ->action(__('notification.device_action'), url('/devices'));
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OvertimeRequest;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** The employee's overtime request was approved or rejected. */
class OvertimeDecidedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly OvertimeRequest $request,
        private readonly bool $approved,
    ) {}

    protected function preferenceType(): string
    {
        return 'overtime_request';
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $this->filterChannels($notifiable, $channels);
    }

    public function toArray(object $notifiable): array
    {
        $date = $this->request->date->format('Y-m-d');

        return [
            'overtime_request_id' => $this->request->public_id,
            'date' => $date,
            'action' => $this->approved ? 'approved' : 'rejected',
            'message' => $this->approved
                ? __('notification.inapp.overtime_approved', ['date' => $date])
                : __('notification.inapp.overtime_rejected', ['date' => $date]),
            'url' => '/attendance/overtime',
        ];
    }
}

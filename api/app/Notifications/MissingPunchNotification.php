<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Employee;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Notifications\Contracts\BulkMail;
use App\Support\FrontendUrl;
use App\Support\NotificationMessage;
use App\Support\NotificationTemplates;
use App\Support\ReaderDates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissingPunchNotification extends Notification implements BulkMail
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly Employee $employee,
        private readonly string $date,
        private readonly string $type, // 'missing_check_out' | 'missing_check_in'
    ) {}

    protected function preferenceType(): string
    {
        return 'attendance_anomaly';
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];
        if (config('broadcasting.default') === 'reverb') {
            $channels[] = 'broadcast';
        }

        return $this->filterChannels($notifiable, $channels);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'employee_id' => $this->employee->public_id,
            'employee_name' => $this->employee->name,
            'date' => $this->date,
            'type' => $this->type,
            ...NotificationMessage::make($this->type === 'missing_check_out' ? 'notification.inapp.missing_check_out' : 'notification.inapp.missing_check_in', ['name' => $this->employee->name, 'date' => $this->date], dates: ['date' => 'date'], reader: ReaderDates::for($notifiable)),
            'url' => '/attendance',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $checkOut = $this->type === 'missing_check_out';
        $label = match (app()->getLocale()) {
            'am' => $checkOut ? 'መውጫ' : 'መግቢያ',
            'om' => $checkOut ? 'bahiinsa' : 'seensa',
            default => $checkOut ? 'check-out' : 'check-in',
        };

        return NotificationTemplates::mail('missing_punch', $this->employee->tenant_id, [
            'employee_name' => $this->employee->name,
            'date' => ReaderDates::for($notifiable)->date($this->date),
            'punch_type' => $label,
        ])->action(__('notification.view_attendance'), FrontendUrl::forNotifiable($notifiable, '/attendance'));
    }
}

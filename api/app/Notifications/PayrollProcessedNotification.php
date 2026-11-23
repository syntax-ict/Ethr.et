<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\PayrollRun;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use App\Support\NotificationMessage;
use App\Support\ReaderDates;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PayrollProcessedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly PayrollRun $payrollRun,
    ) {}

    protected function preferenceType(): NotificationType
    {
        return NotificationType::PAYROLL_PROCESSED;
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels($notifiable, ['database'], broadcast: true);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'payroll_run_id' => $this->payrollRun->public_id,
            'period' => $this->payrollRun->period_label,
            ...NotificationMessage::make('notification.inapp.payroll_processed', ['period' => ReaderDates::periodValue($this->payrollRun)], dates: ['period' => 'period'], reader: ReaderDates::for($notifiable)),
            'url' => '/payroll/'.$this->payrollRun->public_id,
        ];
    }
}

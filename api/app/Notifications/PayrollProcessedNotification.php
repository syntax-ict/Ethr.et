<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollRun;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PayrollProcessedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        private readonly PayrollRun $payrollRun,
    ) {}

    protected function preferenceType(): string
    {
        return 'payroll_processed';
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
        return [
            'payroll_run_id' => $this->payrollRun->public_id,
            'period' => $this->payrollRun->period_label,
            'message' => 'Payroll has been processed for '.$this->payrollRun->period_label,
        ];
    }
}

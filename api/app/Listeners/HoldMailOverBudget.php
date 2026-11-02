<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Mail\MailBudget;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;

/**
 * Skips a notification's email once the day's send budget for its kind is used
 * (MailBudget). Only the mail channel: the in-app notice and push still go.
 * Returning false from a NotificationSending listener cancels that channel.
 */
class HoldMailOverBudget
{
    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail' || MailBudget::allows($event->notification)) {
            return null;
        }

        MailBudget::recordHeld();
        Log::warning('Mail held back: the daily send budget for this kind of mail is used', [
            'notification' => $event->notification::class,
            'sent_today' => MailBudget::sentToday(),
            'cap' => MailBudget::cap(),
        ]);

        return false;
    }
}

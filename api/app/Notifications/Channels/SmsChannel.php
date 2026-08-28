<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Contracts\SmsSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Laravel notification channel backing the `sms` preference.
 *
 * Enforces the S26 cap of 5 messages per user per day. The counter is a plain
 * cache key that expires at midnight EAT rather than a rolling window, so a user
 * who hits the cap gets a fresh allowance at the start of their day rather than
 * at an arbitrary hour 24h after their first message.
 */
class SmsChannel
{
    public function __construct(private readonly SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $to = method_exists($notifiable, 'routeNotificationForSms')
            ? $notifiable->routeNotificationForSms($notification)
            : ($notifiable->phone ?? null);

        if (! is_string($to) || $to === '') {
            return;
        }

        if (! $this->withinDailyLimit($notifiable)) {
            return;
        }

        $message = $notification->toSms($notifiable);

        if (! is_string($message) || $message === '') {
            return;
        }

        $this->sender->send($to, $message);
    }

    private function withinDailyLimit(object $notifiable): bool
    {
        $limit = (int) config('sms.daily_limit_per_user', 5);

        if ($limit <= 0) {
            return false;
        }

        $key = 'sms:sent:'.$notifiable->getKey().':'.now('Africa/Addis_Ababa')->format('Y-m-d');
        $sent = (int) Cache::get($key, 0);

        if ($sent >= $limit) {
            return false;
        }

        Cache::put($key, $sent + 1, now('Africa/Addis_Ababa')->endOfDay());

        return true;
    }
}

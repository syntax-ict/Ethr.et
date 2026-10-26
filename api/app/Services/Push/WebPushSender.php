<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\PushSubscription;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends a notification to every browser or phone a user subscribed. Push is
 * off while the VAPID keys are unset (config/webpush.php): nothing is sent
 * and nothing fails.
 *
 * An endpoint the push service reports gone (404 or 410: the app was removed,
 * the permission withdrawn) is deleted, so it is not tried again.
 */
class WebPushSender
{
    public function enabled(): bool
    {
        return filled(config('webpush.public_key')) && filled(config('webpush.private_key'));
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string}  $payload
     * @return int pushes the services accepted
     */
    public function toUser(int $userId, array $payload): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $userId)->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $push = $this->client();
        $body = (string) json_encode([
            'title' => $payload['title'],
            'body' => $payload['body'],
            'tag' => $payload['tag'] ?? 'ethr-notification',
            'data' => ['url' => $payload['url'] ?? '/dashboard'],
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), $body);
        }

        $sent = 0;
        try {
            foreach ($push->flush() as $report) {
                $endpoint = $report->getEndpoint();
                $match = $subscriptions->first(fn (PushSubscription $s): bool => $s->endpoint === $endpoint);

                if ($report->isSuccess()) {
                    $sent++;
                    $match?->forceFill(['last_used_at' => Carbon::now()])->save();
                } elseif ($report->isSubscriptionExpired()) {
                    $match?->delete();
                }
            }
        } catch (Throwable $e) {
            // A push service being down must not fail the work that caused
            // the notification; the in-app copy is already stored.
            Log::warning('web push failed', ['error' => $e->getMessage()]);
        }

        return $sent;
    }

    /**
     * web-push 11 takes a PSR-18 client where 9 took a timeout in seconds, so
     * the timeout now lives on the client. Guzzle is already installed and
     * implements PSR-18; passing it keeps discovery from choosing a client.
     */
    protected function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => (string) config('webpush.subject'),
                'publicKey' => (string) config('webpush.public_key'),
                'privateKey' => (string) config('webpush.private_key'),
            ],
        ], ['TTL' => 3600, 'urgency' => 'normal'], new GuzzleClient(['timeout' => 10]));
    }
}

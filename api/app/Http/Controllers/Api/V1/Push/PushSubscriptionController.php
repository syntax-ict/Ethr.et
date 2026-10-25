<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Push;

use App\Http\Controllers\Controller;
use App\Http\Requests\Push\DeletePushSubscriptionRequest;
use App\Http\Requests\Push\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own push subscriptions: this browser or phone, notified while
 * ETHR is closed. Every action reads and writes the signed-in user's rows
 * only; there is nothing of anyone else's to authorise.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly WebPushSender $sender) {}

    /** Whether push is available here, and the key a browser subscribes with. */
    public function config(): JsonResponse
    {
        $key = (string) config('webpush.public_key');

        return response()->json([
            'enabled' => $this->sender->enabled(),
            'public_key' => $this->sender->enabled() ? $key : null,
        ]);
    }

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        abort_unless($this->sender->enabled(), 409, __('notification.push_unavailable'));

        $endpoint = (string) $request->validated('endpoint');
        $user = $request->user();

        // The same endpoint signed in as someone else on a shared phone moves
        // to the new user: one endpoint, one owner.
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'public_key' => (string) $request->validated('keys.p256dh'),
                'auth_token' => (string) $request->validated('keys.auth'),
                'content_encoding' => (string) ($request->validated('content_encoding') ?? 'aes128gcm'),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ],
        );

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(DeletePushSubscriptionRequest $request): JsonResponse
    {
        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint((string) $request->validated('endpoint')))
            ->delete();

        return response()->json(null, 204);
    }

    /** How many devices the caller receives push on. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'devices' => PushSubscription::query()->where('user_id', $request->user()->id)->count(),
        ]);
    }
}

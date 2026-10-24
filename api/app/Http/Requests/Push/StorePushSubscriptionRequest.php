<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A browser's PushSubscription, as `subscription.toJSON()` gives it.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['sometimes', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }
}

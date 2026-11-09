<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use App\Support\PushEndpoint;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * Only a browser push service may be the endpoint (PushEndpoint). A hook,
     * not a rule, so rules() and the generated contract stay as they are.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $endpoint = $this->input('endpoint');
            if (is_string($endpoint) && ! $v->errors()->has('endpoint') && ! PushEndpoint::allowed($endpoint)) {
                $v->errors()->add('endpoint', __('validation.url', ['attribute' => 'endpoint']));
            }
        });
    }
}

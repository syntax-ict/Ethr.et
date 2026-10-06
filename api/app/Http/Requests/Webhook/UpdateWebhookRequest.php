<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Rules\ExternalUrl;
use App\Support\WebhookEvents;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'url', 'max:500', new ExternalUrl],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEvents::ALL)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

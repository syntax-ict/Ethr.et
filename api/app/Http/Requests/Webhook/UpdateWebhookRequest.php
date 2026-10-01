<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Rules\ExternalUrl;
use Illuminate\Foundation\Http\FormRequest;

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
            'events.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Rules\ExternalUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:500', new ExternalUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
        ];
    }
}

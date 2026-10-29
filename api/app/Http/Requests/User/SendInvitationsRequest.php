<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class SendInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize(...) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => ['required_without:scope', 'array', 'min:1', 'max:1000'],
            'user_ids.*' => ['string', 'size:26'],
            'scope' => ['required_without:user_ids', 'string', 'in:not_activated'],
        ];
    }
}

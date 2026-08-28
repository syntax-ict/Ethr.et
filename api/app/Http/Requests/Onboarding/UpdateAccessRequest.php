<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Services\Auth\AuthIdentifierResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'login_identifiers' => ['required', 'array', 'min:1'],
            'login_identifiers.*' => ['string', Rule::in(AuthIdentifierResolver::AVAILABLE)],
            // Optional per-role default identifier, shown in invite flows. Stored
            // as configuration; it does not restrict what the resolver accepts.
            'role_defaults' => ['sometimes', 'array'],
            'role_defaults.*' => ['string', Rule::in(AuthIdentifierResolver::AVAILABLE)],
        ];
    }
}

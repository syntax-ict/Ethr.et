<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The organisation's sign-in page: its own words, help contacts and panel
 * background. Everything here is shown publicly, before sign-in.
 */
class UpdateLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:280'],
            'support_email' => ['nullable', 'email', 'max:120'],
            'support_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{6,24}$/'],
            'background' => ['required', 'string', 'in:brand,gradient,image'],
            'background_image_url' => ['nullable', 'required_if:background,image', 'string', 'url:https', 'max:500'],
        ];
    }
}

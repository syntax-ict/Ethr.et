<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The organisation's public news page: an optional search and category, and
 * the page. Public and unauthenticated, like the rest of the portal feed; the
 * organisation is the one ResolveTenant found for the request.
 */
class LandingNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::in(Announcement::CATEGORIES)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}

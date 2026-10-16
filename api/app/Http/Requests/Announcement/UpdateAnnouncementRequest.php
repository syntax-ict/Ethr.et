<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Announcement\Concerns\ResolvesAnnouncementTarget;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnnouncementRequest extends FormRequest
{
    use ResolvesAnnouncementTarget;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'target_type' => ['sometimes', 'string', 'in:all,department,branch'],
            'target_id' => ['nullable', 'string', 'required_if:target_type,department,branch'],
            'category' => ['nullable', 'string', Rule::in(Announcement::CATEGORIES)],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'show_on_landing' => ['sometimes', 'boolean', 'declined_if:target_type,department,branch'],
            'featured' => ['sometimes', 'boolean'],
        ];
    }
}

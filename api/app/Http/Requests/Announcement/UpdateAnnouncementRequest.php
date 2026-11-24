<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementTarget;
use App\Http\Requests\Announcement\Concerns\ResolvesAnnouncementTarget;
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
            'body' => ['sometimes', 'string', 'max:10000'],
            'priority' => ['sometimes', Rule::enum(AnnouncementPriority::class)],
            'target_type' => ['sometimes', Rule::enum(AnnouncementTarget::class)],
            'target_id' => ['nullable', 'string', 'required_if:target_type,department,branch'],
            'category' => ['nullable', Rule::enum(AnnouncementCategory::class)],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'show_on_landing' => ['sometimes', 'boolean', 'declined_if:target_type,department,branch'],
            'featured' => ['sometimes', 'boolean'],
        ];
    }
}

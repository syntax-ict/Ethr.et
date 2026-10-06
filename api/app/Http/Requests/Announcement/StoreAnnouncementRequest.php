<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Announcement\Concerns\ResolvesAnnouncementTarget;
use Illuminate\Foundation\Http\FormRequest;

class StoreAnnouncementRequest extends FormRequest
{
    use ResolvesAnnouncementTarget;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'target_type' => ['sometimes', 'string', 'in:all,department,branch'],
            'target_id' => ['nullable', 'string', 'required_if:target_type,department,branch'],
            'publish_now' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}

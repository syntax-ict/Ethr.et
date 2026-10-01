<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Announcement\Concerns\ResolvesAnnouncementTarget;
use Illuminate\Foundation\Http\FormRequest;

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
            'expires_at' => ['nullable', 'date'],
        ];
    }
}

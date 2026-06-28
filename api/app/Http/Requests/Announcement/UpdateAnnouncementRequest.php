<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAnnouncementRequest extends FormRequest
{
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
            'target_type' => ['sometimes', 'string', 'in:all,department,branch,role'],
            'target_id' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}

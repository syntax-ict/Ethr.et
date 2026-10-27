<?php

declare(strict_types=1);

namespace App\Http\Requests\Recruitment;

use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording someone who applied. Only what a selection needs: a name, a way
 * to reach them, where the application came from and what they sent.
 */
class StoreJobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'source' => ['required', 'string', 'in:'.implode(',', JobApplication::SOURCES)],
            'cover_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

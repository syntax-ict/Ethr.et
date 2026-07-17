<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_en' => ['nullable', 'string', 'max:200'],
            'subject_am' => ['nullable', 'string', 'max:200'],
            'body_en' => ['nullable', 'string', 'max:2000'],
            'body_am' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

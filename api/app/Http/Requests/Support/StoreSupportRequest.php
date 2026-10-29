<?php

declare(strict_types=1);

namespace App\Http\Requests\Support;

use App\Models\SupportRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:'.implode(',', SupportRequest::CATEGORIES)],
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'send_to' => ['sometimes', 'string', 'in:organisation,ethr'],
            'include_context' => ['sometimes', 'boolean'],
            'context' => ['sometimes', 'nullable', 'array'],
            'context.page' => ['sometimes', 'nullable', 'string', 'max:300'],
            'context.locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'context.viewport' => ['sometimes', 'nullable', 'string', 'max:20'],
            'context.online' => ['sometimes', 'nullable', 'boolean'],
            'context.pending_offline' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'context.recent_errors' => ['sometimes', 'nullable', 'array', 'max:5'],
            'context.recent_errors.*.method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'context.recent_errors.*.path' => ['required', 'string', 'max:300'],
            'context.recent_errors.*.status' => ['required', 'integer', 'min:0', 'max:599'],
            'context.recent_errors.*.type' => ['sometimes', 'nullable', 'string', 'max:200'],
            'context.recent_errors.*.at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}

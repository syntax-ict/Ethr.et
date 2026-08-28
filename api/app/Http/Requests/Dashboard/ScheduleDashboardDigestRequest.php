<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleDashboardDigestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['email'],
            // Ignored for a dashboard.regional-only caller — the controller
            // always forces their own branch, same as every read endpoint in
            // ExecutiveDashboardController.
            'branch_public_id' => ['nullable', 'string', 'exists:branches,public_id'],
        ];
    }
}

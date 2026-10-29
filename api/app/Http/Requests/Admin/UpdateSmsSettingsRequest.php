<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SmsSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'route_prefix' => ['sometimes', 'nullable', 'string', Rule::in(SmsSetting::ROUTE_PREFIXES)],
            'daily_cap' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'invitations_enabled' => ['sometimes', 'boolean'],
            'invitation_share' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}

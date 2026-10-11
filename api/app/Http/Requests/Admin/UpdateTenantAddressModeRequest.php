<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\TenantAddressMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The platform console setting an organisation's address mode on its behalf:
 * `path`, `subdomain` or `custom_domain`. The domain itself is assigned
 * through the separate domain endpoint, as before.
 */
class UpdateTenantAddressModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(TenantAddressMode::class)],
        ];
    }
}

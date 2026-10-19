<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting an organisation asks for three things at once: its address typed
 * out, a current authenticator code, and the reason. The typed address is
 * compared with the tenant in the controller, which is the only place that
 * knows which tenant the route names.
 */
class DeleteTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'size:6'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

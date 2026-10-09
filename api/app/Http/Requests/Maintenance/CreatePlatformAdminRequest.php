<?php

declare(strict_types=1);

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST /api/v1/maintenance/create-admin`. The rules are the ones
 * `ethr:create-admin` applies, so the route cannot create an account the
 * command would refuse.
 */
class CreatePlatformAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        // VerifyMaintenanceToken is the authority for this route.
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ];
    }
}

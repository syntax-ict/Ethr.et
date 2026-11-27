<?php

declare(strict_types=1);

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The maintenance task that erases a deleted organisation. The address is
 * typed twice to execute, as a restore's backup name is: an erasure cannot be
 * undone except by restoring every organisation.
 */
class EraseOrganisationRequest extends FormRequest
{
    /** VerifyMaintenanceToken on the route is the authority. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
            'execute' => ['sometimes', 'boolean'],
            'confirm' => ['required_if_accepted:execute', 'nullable', 'string', 'same:address'],
        ];
    }
}

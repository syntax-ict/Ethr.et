<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting an organisation asks for two things at once: a current
 * authenticator code and the reason. The code is checked in the controller,
 * once the organisation is known to be cancelled.
 *
 * Until 2026-10-26 it also asked for the address typed out. The owner took
 * that step away: the dialog names the organisation and its address, it must
 * already be cancelled, and the code is what proves a person is at the
 * console. A client still sending `confirm` is not refused for it.
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
            'code' => ['required', 'string', 'size:6'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

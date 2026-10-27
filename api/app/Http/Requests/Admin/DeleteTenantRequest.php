<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deleting an organisation asks for one thing: the reason, recorded in the
 * audit log. The organisation must already be cancelled (checked in the
 * controller), and the route is behind RequirePlatformMfa.
 *
 * Owner decisions took two earlier steps away: the address typed out on
 * 2026-10-26, and a fresh authenticator code on 2026-10-27. A client still
 * sending `confirm` or `code` is not refused for it; both are ignored.
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
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

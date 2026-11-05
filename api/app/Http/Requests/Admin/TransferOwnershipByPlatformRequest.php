<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TransferOwnershipByPlatformRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'user_public_id' => ['required', 'string', 'max:26'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}

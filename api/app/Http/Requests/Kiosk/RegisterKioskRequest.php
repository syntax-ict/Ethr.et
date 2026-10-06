<?php

declare(strict_types=1);

namespace App\Http\Requests\Kiosk;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RegisterKioskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'branch_public_id' => ['required', 'string'],
            'admin_pin' => ['required', 'string', 'min:4', 'max:8', 'regex:/^\d+$/'],
            'device_identifier' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The branch must be this tenant's. An unscoped `exists:` rule answered a
     * nonexistent id with 422 and another tenant's real branch with the
     * controller's 404 — confirming, across tenants, that the id exists. The
     * lookup runs through the tenant scope and both cases get the message a
     * nonexistent id gets.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $publicId = $this->input('branch_public_id');
            if (! is_string($publicId) || $validator->errors()->has('branch_public_id')) {
                return;
            }

            if (! Branch::query()->where('public_id', $publicId)->exists()) {
                $validator->errors()->add('branch_public_id', __('validation.exists', ['attribute' => 'branch public id']));
            }
        });
    }
}

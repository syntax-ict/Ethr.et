<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BackupDriveSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveBackupDriveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_secret' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** The secret is write-only: an empty field keeps the stored one. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || filled($this->input('client_secret'))) {
                return;
            }

            if (! BackupDriveSetting::current() instanceof BackupDriveSetting) {
                $validator->errors()->add('client_secret', __('validation.required', ['attribute' => 'client secret']));
            }
        });
    }
}

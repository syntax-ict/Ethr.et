<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BackupStorageSetting;
use App\Rules\ExternalUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveBackupStorageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500', 'url', 'starts_with:https://', new ExternalUrl],
            'region' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/'],
            'access_key_id' => ['required', 'string', 'max:191'],
            'secret_access_key' => ['nullable', 'string', 'max:500'],
            'path_style' => ['required', 'boolean'],
        ];
    }

    /**
     * The secret is write-only: an empty field keeps the stored one, so it is
     * required only when none is stored yet.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || filled($this->input('secret_access_key'))) {
                return;
            }

            if (! BackupStorageSetting::current() instanceof BackupStorageSetting) {
                $validator->errors()->add('secret_access_key', __('validation.required', ['attribute' => 'secret access key']));
            }
        });
    }
}

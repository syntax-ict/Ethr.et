<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BackupStorageSetting;
use App\Rules\ExternalUrl;
use App\Support\OutboundHost;
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
     *
     * Without path-style addressing the client connects to `{bucket}.{host}`,
     * a different name from the endpoint ExternalUrl checked, so that name is
     * checked too.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $host = BackupStorageSetting::hostFor((string) $this->input('endpoint'), (string) $this->input('bucket'), $this->boolean('path_style'));
            if (OutboundHost::isInternal($host)) {
                $validator->errors()->add('bucket', 'The bucket must not point to an internal address.');

                return;
            }

            if (filled($this->input('secret_access_key'))) {
                return;
            }

            if (! BackupStorageSetting::current() instanceof BackupStorageSetting) {
                $validator->errors()->add('secret_access_key', __('validation.required', ['attribute' => 'secret access key']));
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\SsoSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateSsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settings.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_enabled' => ['boolean'],
            'idp_entity_id' => ['nullable', 'string', 'max:500'],
            'idp_sso_url' => ['nullable', 'url', 'max:2048'],
            'idp_slo_url' => ['nullable', 'url', 'max:2048'],
            'idp_certificate' => ['nullable', 'string'],
            'default_role' => ['nullable', 'string', 'in:employee,supervisor,dept_admin,hr_admin'],
            'auto_provision' => ['boolean'],
            'attribute_mapping' => ['nullable', 'array'],
        ];
    }

    /**
     * SSO may be switched on only when it can work: an identity provider
     * entity id, its sign-in URL and its certificate. The certificate is
     * write-only, so "already stored" counts and a save that leaves it out does
     * not have to paste it again. Judged on the state the save would leave, for
     * a save that turns SSO on or touches one of those three fields.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $input = $this->validated();
            $touchesIdp = array_intersect_key($input, array_flip(['idp_entity_id', 'idp_sso_url', 'idp_certificate'])) !== [];
            $stored = SsoSetting::query()->first();

            $enabled = array_key_exists('is_enabled', $input)
                ? (bool) $input['is_enabled']
                : (bool) $stored?->is_enabled;

            $turnsOn = array_key_exists('is_enabled', $input) && $enabled;

            if (! $enabled || ! ($turnsOn || $touchesIdp)) {
                return;
            }

            foreach (['idp_entity_id', 'idp_sso_url', 'idp_certificate'] as $field) {
                $value = array_key_exists($field, $input) ? $input[$field] : ($field === 'idp_certificate' ? $stored?->getRawOriginal($field) : $stored?->{$field});

                if (! is_string($value) || trim($value) === '') {
                    $validator->addFailure($field, 'required');
                }
            }
        });
    }
}

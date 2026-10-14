<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SmsGateway;
use App\Rules\ExternalUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSmsGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            'endpoint' => ['required', 'string', 'max:500', 'url', new ExternalUrl],
            'method' => ['required', 'string', Rule::in(SmsGateway::METHODS)],
            'auth_type' => ['required', 'string', Rule::in(SmsGateway::AUTH_TYPES)],
            'auth_header' => ['nullable', 'required_if:auth_type,header', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/'],
            'auth_username' => ['nullable', 'required_if:auth_type,fields,basic', 'string', 'max:191'],
            'auth_secret' => ['nullable', 'string', 'max:2000'],
            'username_field' => ['nullable', 'required_if:auth_type,fields', 'string', 'max:64'],
            'password_field' => ['nullable', 'required_if:auth_type,fields', 'string', 'max:64'],
            'recipient_field' => ['required', 'string', 'max:64'],
            'message_field' => ['required', 'string', 'max:64'],
            'sender_field' => ['nullable', 'string', 'max:64'],
            'sender_id' => ['nullable', 'string', 'max:64'],
            'number_format' => ['required', 'string', Rule::in(SmsGateway::NUMBER_FORMATS)],
            'success_json_path' => ['nullable', 'string', 'max:191'],
            'success_json_value' => ['nullable', 'required_with:success_json_path', 'string', 'max:191'],
            'success_body_contains' => ['nullable', 'string', 'max:191'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }

    /**
     * A credential is write-only: an empty field keeps the stored one. So it is
     * required only when the chosen authentication needs one and none is
     * stored yet.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('auth_type') === 'none') {
                return;
            }

            $slot = (string) $this->route('slot');
            $stored = SmsGateway::query()->where('slot', $slot)->first();

            if (blank($this->input('auth_secret')) && ! ($stored instanceof SmsGateway && $stored->hasSecret())) {
                $validator->errors()->add('auth_secret', __('validation.required', ['attribute' => 'auth secret']));
            }
        });
    }
}

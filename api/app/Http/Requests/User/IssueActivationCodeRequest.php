<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Services\Auth\ActivationCodeService;
use App\Services\MfaService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * HR attests they checked the person's identity face to face, names the
 * document (never its number), and proves it is really them at the keyboard:
 * their password, and their authenticator code when they have one.
 *
 * The step-up lives in `withValidator()` so a wrong password and a wrong code
 * read as the same 422 as any other field, and `rules()` stays plain for the
 * generated API contract.
 */
class IssueActivationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'attested' => ['required', 'accepted'],
            'document_type' => ['required', 'string', Rule::in(ActivationCodeService::DOCUMENT_TYPES)],
            'note' => ['nullable', 'string', 'max:255'],
            'current_password' => ['required', 'string'],
            'mfa_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();

            if ($actor === null || $validator->errors()->has('current_password')) {
                return;
            }

            if (! Hash::check((string) $this->input('current_password'), (string) $actor->password)) {
                $validator->errors()->add('current_password', __('validation.current_password'));

                return;
            }

            if ($actor->mfa_enabled) {
                $code = (string) $this->input('mfa_code', '');
                $secret = (string) $actor->mfa_secret;

                if ($code === '' || $secret === '' || ! app(MfaService::class)->verify($secret, $code)) {
                    $validator->errors()->add('mfa_code', __('validation.current_password'));
                }
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\User\Concerns;

use App\Services\MfaService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * Before a sensitive action, the person at the keyboard proves it is really
 * them: their own password, and their authenticator code when they have one.
 *
 * Used from `withValidator()`, so a wrong password and a wrong code read as
 * the same 422 as any other field, and `rules()` stays plain for the generated
 * API contract. The request declares `current_password` (required) and
 * `mfa_code` (nullable) itself.
 *
 * @mixin FormRequest
 */
trait ConfirmsItIsThem
{
    protected function confirmItIsThem(Validator $validator): void
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

                // verifyFor counts a wrong code toward the account's lockout.
                if ($code === '' || (string) $actor->mfa_secret === '' || ! app(MfaService::class)->verifyFor($actor, $code)) {
                    $validator->errors()->add('mfa_code', __('validation.current_password'));
                }
            }
        });
    }
}

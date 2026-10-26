<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The employee's own redemption: their employee code, the one-time code HR
 * gave them, and the password they choose. The password may not be the
 * employee code, the one thing anyone who has seen their badge already knows.
 */
class RedeemActivationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_code' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', new PasswordPolicy, 'confirmed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $password = mb_strtolower((string) $this->input('password'));

            if ($password !== '' && $password === mb_strtolower(trim((string) $this->input('employee_code')))) {
                $validator->errors()->add('password', __('validation.different', [
                    'attribute' => 'password',
                    'other' => 'employee code',
                ]));
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\User\Concerns\ConfirmsItIsThem;
use App\Services\Auth\StartingPasswords;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * HR names the employees and proves it is really them at the keyboard: their
 * own password, and their authenticator code when they have one. The starting
 * password itself is never sent; ETHR makes it.
 *
 * Permission is checked here as well as in the controller, so someone without
 * it is refused before their password is looked at.
 */
class IssueStartingPasswordRequest extends FormRequest
{
    use ConfirmsItIsThem;

    public function authorize(): bool
    {
        return Gate::allows('users.issueStartingPassword');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.StartingPasswords::MAX_BATCH],
            'employee_ids.*' => ['string', 'size:26', 'distinct'],
            'current_password' => ['required', 'string'],
            'mfa_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->confirmItIsThem($validator);
    }
}

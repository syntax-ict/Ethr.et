<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\User\Concerns\ConfirmsItIsThem;
use App\Services\Auth\ActivationCodeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
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
    use ConfirmsItIsThem;

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
        $this->confirmItIsThem($validator);
    }
}

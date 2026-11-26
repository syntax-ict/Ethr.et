<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Http\Requests\User\Concerns\ConfirmsItIsThem;
use App\Services\Auth\ActivationCodeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * A sheet of activation codes, one for each employee named.
 *
 * HR undertakes to check each person's identity when their slip is handed
 * over (`attested`), and proves it is really them at the keyboard: their own
 * password, and their authenticator code when they have one. `hours` is how
 * long the sheet's codes live; a sheet cannot be handed out in a day, so it
 * may be up to a week.
 *
 * Permission is checked here as well as in the controller, so someone
 * without it is refused before their password is looked at.
 */
class IssueActivationCodesRequest extends FormRequest
{
    use ConfirmsItIsThem;

    public function authorize(): bool
    {
        return Gate::allows('users.issueActivationCode');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.ActivationCodeService::SHEET_MAX],
            'employee_ids.*' => ['string', 'size:26', 'distinct'],
            'attested' => ['required', 'accepted'],
            'hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.ActivationCodeService::SHEET_MAX_TTL_HOURS],
            'current_password' => ['required', 'string'],
            'mfa_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->confirmItIsThem($validator);
    }
}

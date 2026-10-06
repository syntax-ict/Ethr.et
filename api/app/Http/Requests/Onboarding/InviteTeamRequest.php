<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class InviteTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'array', 'min:1', 'max:50'],
            'emails.*' => ['required', 'email', 'max:255'],
            'role' => ['sometimes', 'string', 'in:employee,supervisor,dept_admin,finance_admin,hr_admin'],
        ];
    }
}

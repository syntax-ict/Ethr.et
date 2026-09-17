<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `exists` in string form rather than `Rule::exists(...)`, matching every
     * other request in this directory. Scramble derives the public schema from
     * these rules and emits a plain `string` for the string form, which is what
     * `generated.ts` already carries; the builder object is untested here and
     * the contract gate fails on drift.
     *
     * Existence only. Whether the plan is still on sale is enforced in
     * BillingController, because expressing it here needs that builder.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'plan_public_id' => ['required', 'string', 'exists:plans,public_id'],
        ];
    }
}

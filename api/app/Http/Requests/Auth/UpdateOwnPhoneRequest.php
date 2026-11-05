<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOwnPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The local `09…`/`07…` form and `+251…` resolve to one stored form, as at
     * registration. Unparseable input passes through for the rule to report.
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone) && $phone !== '') {
            $this->merge(['phone' => EthiopianPhone::canonical($phone) ?? $phone]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+251\d{9}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => __('auth.phone_invalid'),
        ];
    }
}

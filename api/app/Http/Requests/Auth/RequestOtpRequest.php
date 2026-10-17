<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'phone' => ['required_without:email', 'prohibits:email', 'nullable', 'string', 'max:20'],
            'email' => ['required_without:phone', 'nullable', 'string', 'email', 'max:255'],
            // Read by the ResolveTenant middleware when the request is not on a
            // tenant subdomain, ahead of any X-Tenant header.
            'tenant' => ['nullable', 'string'],
        ];
    }

    /**
     * A landline can never receive the text, so it is refused here with a
     * clear message rather than answered "code sent" and then never sent. The
     * check is on the number's shape alone, so it says nothing about whether
     * an account holds it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $phone = $this->input('phone');

            if ($validator->errors()->isNotEmpty() || ! is_string($phone) || $phone === '') {
                return;
            }

            if (! EthiopianPhone::isMobile($phone)) {
                $validator->errors()->add('phone', __('auth.sms_mobile_only'));
            }
        });
    }
}

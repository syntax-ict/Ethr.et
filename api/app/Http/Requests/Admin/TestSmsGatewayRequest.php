<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TestSmsGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
        ];
    }

    /** Only a mobile number can receive the test; a landline is refused here. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('phone')) {
                return;
            }

            if (! EthiopianPhone::isMobile((string) $this->input('phone'))) {
                $validator->errors()->add('phone', __('auth.sms_mobile_only'));
            }
        });
    }
}

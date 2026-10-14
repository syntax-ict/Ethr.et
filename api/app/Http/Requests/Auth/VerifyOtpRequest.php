<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Auth\OtpService;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:'.OtpService::CODE_LENGTH],
            'tenant' => ['nullable', 'string'],
        ];
    }
}

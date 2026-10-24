<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Support\PunchSignals;
use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            ...PunchSignals::rules(),
        ];
    }
}

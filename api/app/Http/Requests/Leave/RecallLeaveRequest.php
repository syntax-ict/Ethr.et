<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;

class RecallLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class AssignUnmatchedBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_public_id' => ['required', 'string', 'size:26'],
            'badge' => ['required', 'string', 'max:64'],
            'employee_public_id' => ['required', 'string', 'size:26'],
        ];
    }
}

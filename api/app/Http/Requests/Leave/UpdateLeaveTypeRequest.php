<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Enums\AccrualType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'name_am' => ['sometimes', 'nullable', 'string', 'max:100'],
            'code' => ['sometimes', 'string', 'max:30'],
            'default_days' => ['sometimes', 'numeric', 'min:0.5', 'max:365'],
            'accrual_type' => ['sometimes', new Enum(AccrualType::class)],
            'carry_forward' => ['sometimes', 'boolean'],
            'max_carry_days' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'requires_approval' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'min_notice_days' => ['sometimes', 'integer', 'min:0'],
            'max_consecutive' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_paid' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'gender_restriction' => ['sometimes', 'nullable', 'string', 'in:male,female'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

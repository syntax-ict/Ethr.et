<?php

declare(strict_types=1);

namespace App\Http\Requests\Leave;

use App\Enums\AccrualType;
use App\Http\Requests\Leave\Concerns\ValidatesLeaveTypeIntegrity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreLeaveTypeRequest extends FormRequest
{
    use ValidatesLeaveTypeIntegrity;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'name_am' => ['nullable', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:30'],
            'default_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'accrual_type' => ['required', new Enum(AccrualType::class)],
            'carry_forward' => ['nullable', 'boolean'],
            'max_carry_days' => ['nullable', 'numeric', 'min:0'],
            'seniority_every_years' => ['nullable', 'integer', 'min:1', 'max:10', 'required_with:seniority_extra_days'],
            'seniority_extra_days' => ['nullable', 'numeric', 'min:0', 'max:30', 'required_with:seniority_every_years'],
            'requires_approval' => ['nullable', 'boolean'],
            'requires_attachment' => ['nullable', 'boolean'],
            'min_notice_days' => ['nullable', 'integer', 'min:0'],
            'max_consecutive' => ['nullable', 'integer', 'min:1'],
            'is_paid' => ['nullable', 'boolean'],
            'gender_restriction' => ['nullable', 'string', 'in:male,female'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

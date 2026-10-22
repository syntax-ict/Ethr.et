<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Asking to work overtime on a day. Without `employee_public_id` the request
 * is for the caller; with one, HR asks on that employee's behalf (the
 * controller authorises which). A day can carry one open request at a time:
 * another for the same day while one is pending or approved is refused, so
 * nobody approves the same hours twice.
 */
class StoreOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_public_id' => ['nullable', 'string', 'size:26'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'planned_minutes' => ['required', 'integer', 'min:15', 'max:'.OvertimeRequest::MAX_PLANNED_MINUTES],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $publicId = $this->input('employee_public_id');
            if ($publicId !== null && ! Employee::query()->where('public_id', $publicId)->exists()) {
                $validator->errors()->add('employee_public_id', __('validation.exists', ['attribute' => 'employee_public_id']));

                return;
            }
        });
    }
}

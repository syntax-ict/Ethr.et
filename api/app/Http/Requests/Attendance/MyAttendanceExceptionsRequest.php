<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/** An optional period, at most 92 days, as for the payroll reconciliation. */
class MyAttendanceExceptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_start' => ['nullable', 'date_format:Y-m-d', 'required_with:period_end'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'required_with:period_start', 'after_or_equal:period_start'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('period_start')) {
                return;
            }

            $days = Carbon::parse((string) $this->input('period_start'))
                ->diffInDays(Carbon::parse((string) $this->input('period_end')));

            if ($days > 91) {
                $validator->errors()->add('period_end', __('payroll.reconciliation_range'));
            }
        });
    }
}

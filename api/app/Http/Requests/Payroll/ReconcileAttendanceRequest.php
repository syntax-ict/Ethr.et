<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * The pay period to reconcile. At most 92 days, the longest range the
 * absences report reads, because every employee's expected days are worked
 * out for each day of it.
 */
class ReconcileAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
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

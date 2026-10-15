<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Models\PayrollRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProcessPayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * One live run per period. Until 2026-10-15 nothing enforced that: only
     * the idempotency key deduplicated, so a second click from another session
     * created a second completed run over the same days, and only a careful
     * human stood between that and approving both (production QA, F2). Voided
     * and failed runs do not block — voiding is exactly how a period is redone
     * — and a replayed idempotency key is excluded so convention 10's replay
     * still answers 200 with the existing run instead of failing here.
     *
     * A withValidator hook, not a rule: Scramble derives the API contract from
     * rules(), which stays byte-identical.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $overlapping = PayrollRun::query()
                ->whereNotIn('status', ['voided', 'failed'])
                // whereNull, or a run with no key would never block: SQL's
                // NULL != 'x' is not true.
                ->where(fn ($q) => $q
                    ->whereNull('idempotency_key')
                    ->orWhere('idempotency_key', '!=', (string) $this->input('idempotency_key')))
                ->whereDate('period_start', '<=', $this->input('period_end'))
                ->whereDate('period_end', '>=', $this->input('period_start'))
                ->orderByDesc('period_start')
                ->first();

            if ($overlapping !== null) {
                $validator->errors()->add('period_start', __('payroll.period_overlaps', [
                    'label' => $overlapping->period_label,
                    'start' => $overlapping->period_start?->toDateString(),
                    'end' => $overlapping->period_end?->toDateString(),
                ]));
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces a tenant's whole income-tax ladder in one call. A ladder is only
 * meaningful as a set, so the brackets are validated for contiguity here
 * rather than allowing per-bracket edits that could leave gaps or overlaps.
 *
 * `max_amount_cents` may be null on the final bracket to mark it open-ended.
 */
class ReplaceTaxBracketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'brackets' => ['required', 'array', 'min:1', 'max:20'],
            'brackets.*.min_amount_cents' => ['required', 'integer', 'min:0'],
            'brackets.*.max_amount_cents' => ['present', 'nullable', 'integer', 'min:1'],
            'brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'brackets.*.deduction_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<array{min_amount_cents: int, max_amount_cents: int|null, rate: float, deduction_cents: int}> $brackets */
            $brackets = $this->input('brackets', []);

            if (($brackets[0]['min_amount_cents'] ?? null) !== 0) {
                $validator->errors()->add('brackets.0.min_amount_cents', __('payroll.tax_bracket_must_start_at_zero'));
            }

            $last = count($brackets) - 1;

            foreach ($brackets as $i => $bracket) {
                $min = (int) $bracket['min_amount_cents'];
                $max = $bracket['max_amount_cents'];

                if ($i < $last && $max === null) {
                    $validator->errors()->add("brackets.{$i}.max_amount_cents", __('payroll.tax_bracket_only_last_open_ended'));

                    continue;
                }

                if ($max !== null && (int) $max <= $min) {
                    $validator->errors()->add("brackets.{$i}.max_amount_cents", __('payroll.tax_bracket_max_after_min'));

                    continue;
                }

                if ($i < $last) {
                    $nextMin = (int) $brackets[$i + 1]['min_amount_cents'];

                    if ($nextMin !== (int) $max + 1) {
                        $validator->errors()->add("brackets.{$i}.max_amount_cents", __('payroll.tax_bracket_not_contiguous'));
                    }
                }
            }
        });
    }
}

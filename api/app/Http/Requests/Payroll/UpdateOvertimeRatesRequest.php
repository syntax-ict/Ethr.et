<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Services\CurrentTenant;
use App\Services\Payroll\OvertimeCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Tenant overtime multipliers. A tenant may pay above the statutory floor
 * for its organisation type (Labour Proclamation 1156/2019 Art. 68(1)), never
 * below it. `rest_day` is optional so a client that predates it keeps
 * working; the floor applies.
 */
class UpdateOvertimeRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'normal' => ['required', 'numeric', 'min:1', 'max:10'],
            'night' => ['required', 'numeric', 'min:1', 'max:10'],
            'rest_day' => ['sometimes', 'numeric', 'min:1', 'max:10'],
            'holiday' => ['required', 'numeric', 'min:1', 'max:10'],
            'holiday_night' => ['required', 'numeric', 'min:1', 'max:10'],
        ];
    }

    /**
     * The floor is the `overtime.minimum_rates` statutory parameter; until
     * 2026-10-27 it was written into rules() as numbers. Checked here, not as
     * `min:` in rules(): it is a database row, and Scramble evaluates rules()
     * where no database may be reachable.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $floor = app(OvertimeCalculator::class)->minimumRates(app(CurrentTenant::class)->id());

            foreach ($floor as $type => $minimum) {
                $value = $this->input($type);
                if (is_numeric($value) && (float) $value < $minimum) {
                    $v->errors()->add($type, __('validation.min.numeric', ['attribute' => str_replace('_', ' ', $type), 'min' => $minimum]));
                }
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use App\Models\ShiftAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EndShiftAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_to' => ['required', 'date', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The new last day may not fall before the assignment's first. Checked
     * here rather than as `after_or_equal:` because the first day lives on the
     * bound route model, not in the request body.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assignment = $this->route('assignment');
            $effectiveTo = $this->input('effective_to');

            if (! $assignment instanceof ShiftAssignment
                || ! is_string($effectiveTo)
                || $validator->errors()->has('effective_to')
                || $assignment->effective_from === null) {
                return;
            }

            $from = $assignment->effective_from->format('Y-m-d');

            if ($effectiveTo < $from) {
                $validator->errors()->add('effective_to', __('validation.after_or_equal', [
                    'attribute' => 'effective to',
                    'date' => $from,
                ]));
            }
        });
    }
}
